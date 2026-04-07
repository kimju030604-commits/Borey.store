<?php
/**
 * OCR Helper - Extract text from bank transaction screenshots
 * Uses Tesseract OCR if available
 */

function extractTextFromImage($imagePath) {
    if (!file_exists($imagePath)) {
        return ['success' => false, 'text' => '', 'error' => 'Image not found'];
    }
    
    // Try Tesseract OCR
    $tesseractPath = 'tesseract'; // Default, assumes in PATH
    
    // Check common Windows paths
    $windowsPaths = [
        'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
        'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
    ];
    
    foreach ($windowsPaths as $path) {
        if (file_exists($path)) {
            $tesseractPath = '"' . $path . '"';
            break;
        }
    }
    
    // Create temp output file
    $tempOutput = sys_get_temp_dir() . '/ocr_' . uniqid();
    
    // Run Tesseract with English + Khmer language support
    $command = "$tesseractPath " . escapeshellarg($imagePath) . " $tempOutput -l eng+khm 2>&1";
    
    exec($command, $output, $returnCode);
    
    $resultFile = $tempOutput . '.txt';
    
    if (file_exists($resultFile)) {
        $text = file_get_contents($resultFile);
        unlink($resultFile);
        return ['success' => true, 'text' => $text, 'error' => null];
    }
    
    // Tesseract not available or failed
    return [
        'success' => false, 
        'text' => '', 
        'error' => 'OCR failed. Tesseract may not be installed.'
    ];
}

/**
 * Extract sender name from bank transaction text
 * Supports common Cambodian bank formats
 */
function extractSenderName($ocrText) {
    if (empty($ocrText)) {
        return null;
    }
    
    $lines = explode("\n", $ocrText);
    $senderName = null;
    
    // Common patterns for sender name in bank transfers
    $patterns = [
        // English patterns
        '/(?:From|Sender|Transferred by|Account Name|Payer)[:\s]+([A-Za-z\s]+)/i',
        '/(?:Name)[:\s]+([A-Za-z\s]+)/i',
        // After "From" keyword
        '/From\s*[:\-]?\s*(.+)/i',
        // ABA Bank format
        '/(?:Sender Name|From Account)[:\s]+(.+)/i',
        // ACLEDA format  
        '/(?:Debited From|Transfer From)[:\s]+(.+)/i',
        // Wing format
        '/(?:Sent by|From)[:\s]+(.+)/i',
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $ocrText, $matches)) {
            $name = trim($matches[1]);
            // Clean up the name
            $name = preg_replace('/[0-9]{4,}/', '', $name); // Remove account numbers
            $name = preg_replace('/\s+/', ' ', $name); // Normalize spaces
            $name = trim($name);
            
            if (strlen($name) >= 2 && strlen($name) <= 100) {
                return $name;
            }
        }
    }
    
    // Try to find lines that look like names (capitalized words)
    foreach ($lines as $line) {
        $line = trim($line);
        // Skip empty lines and lines with numbers/special chars
        if (empty($line) || preg_match('/[0-9]{6,}/', $line)) {
            continue;
        }
        // Look for properly capitalized names
        if (preg_match('/^[A-Z][a-z]+(?:\s+[A-Z][a-z]+)+$/', $line)) {
            return $line;
        }
    }
    
    return null;
}

/**
 * Process bank transaction screenshot and extract sender name
 */
function processReceiptForSenderName($imagePath) {
    $result = extractTextFromImage($imagePath);
    
    if (!$result['success']) {
        return [
            'success' => false,
            'sender_name' => null,
            'raw_text' => '',
            'error' => $result['error']
        ];
    }
    
    $senderName = extractSenderName($result['text']);
    
    return [
        'success' => true,
        'sender_name' => $senderName,
        'raw_text' => $result['text'],
        'error' => null
    ];
}
