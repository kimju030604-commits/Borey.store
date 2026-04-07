<?php
/**
 * InvoicePDF  pixel-faithful replica of the HTML invoice (FPDF)
 */
require_once __DIR__ . '/fpdf.php';

class InvoicePDF extends FPDF
{
    protected $invoice;

    /*  colour palette  */
    const NAVY    = [15,  23,  42];
    const BLUE9   = [30,  58, 138];
    const BLUE7   = [29,  78, 216];
    const BLUE6   = [37,  99, 235];
    const BLUE4   = [96, 165, 250];
    const GREEN5  = [34, 197,  94];
    const GREEN6  = [22, 163,  74];
    const SLATE7  = [51,  65,  85];
    const SLATE6  = [71,  85, 105];
    const SLATE5  = [100,116, 139];
    const SLATE4  = [148,163, 184];
    const SLATE2  = [226,232, 240];
    const SLATE1  = [248,250, 252];
    const WHITE   = [255,255, 255];

    function __construct($invoice)
    {
        parent::__construct('P', 'mm', 'A4');
        $this->invoice = $invoice;
        $this->SetMargins(18, 14, 18);
        $this->SetAutoPageBreak(true, 28);
    }

    private function rgb($c)  { $this->SetTextColor(...$c); }
    private function fill($c) { $this->SetFillColor(...$c); }
    private function draw($c) { $this->SetDrawColor(...$c); }
    private function t($s)    { return iconv('UTF-8','ISO-8859-1//TRANSLIT//IGNORE',(string)$s); }

    private function roundedRect($x,$y,$w,$h,$r,$style='F')
    {
        $k=$this->k; $hp=$this->h;
        $op=($style==='F')?'f':(($style==='FD'||$style==='DF')?'B':'S');
        $a=4/3*(sqrt(2)-1);
        $this->_out(sprintf('%.2F %.2F m',($x+$r)*$k,($hp-$y)*$k));
        $xc=$x+$w-$r; $yc=$y+$r;
        $this->_out(sprintf('%.2F %.2F l',$xc*$k,($hp-$y)*$k));
        $this->_Arc($xc+$r*$a,$yc-$r,$xc+$r,$yc-$r*$a,$xc+$r,$yc);
        $xc=$x+$w-$r; $yc=$y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l',($x+$w)*$k,($hp-$yc)*$k));
        $this->_Arc($xc+$r,$yc+$r*$a,$xc+$r*$a,$yc+$r,$xc,$yc+$r);
        $xc=$x+$r; $yc=$y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l',$xc*$k,($hp-($y+$h))*$k));
        $this->_Arc($xc-$r*$a,$yc+$r,$xc-$r,$yc+$r*$a,$xc-$r,$yc);
        $xc=$x+$r; $yc=$y+$r;
        $this->_out(sprintf('%.2F %.2F l',($x)*$k,($hp-$yc)*$k));
        $this->_Arc($xc-$r,$yc-$r*$a,$xc-$r*$a,$yc-$r,$xc,$yc-$r);
        $this->_out($op);
    }
    private function _Arc($x1,$y1,$x2,$y2,$x3,$y3)
    {
        $h=$this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            $x1*$this->k,($h-$y1)*$this->k,$x2*$this->k,($h-$y2)*$this->k,
            $x3*$this->k,($h-$y3)*$this->k));
    }

    /*  HEADER  */
    function Header()
    {
        $W=$this->GetPageWidth(); $hH=40;
        // gradient strips navyblue9
        $steps=60;
        for($i=0;$i<$steps;$i++){
            $t=$i/($steps-1);
            $this->SetFillColor(
                intval(self::NAVY[0]+$t*(self::BLUE9[0]-self::NAVY[0])),
                intval(self::NAVY[1]+$t*(self::BLUE9[1]-self::NAVY[1])),
                intval(self::NAVY[2]+$t*(self::BLUE9[2]-self::NAVY[2]))
            );
            $this->Rect($W*$i/$steps,0,$W/$steps+0.5,$hH,'F');
        }
        // BOREY white + .STORE blue
        $this->SetFont('Arial','B',24);
        $this->rgb(self::WHITE);
        $this->SetXY(18,11);
        $bw=$this->GetStringWidth('BOREY');
        $this->Cell($bw,10,'BOREY',0,0,'L');
        $this->rgb(self::BLUE4);
        $this->Cell(0,10,'.STORE',0,1,'L');
        // subtitle
        $this->SetX(18);
        $this->SetFont('Arial','',9);
        $this->SetTextColor(180,200,230);
        $this->Cell(0,5,'Premium Local Marketplace',0,1,'L');
        // PAID badge
        $status=strtoupper($this->invoice['payment_status']??'PAID');
        $bW=26;$bH=8;$bx=$W-18-$bW;$by=9;
        $this->fill(self::GREEN5);
        $this->roundedRect($bx,$by,$bW,$bH,2.5,'F');
        $this->SetFont('Arial','B',7.5);
        $this->rgb(self::WHITE);
        $this->SetXY($bx,$by+1);
        $this->Cell($bW,$bH-2,$status,0,1,'C');
        // INVOICE
        $this->SetFont('Arial','B',20);
        $this->rgb(self::WHITE);
        $this->SetXY(0,25);
        $this->Cell($W-18,9,'INVOICE',0,1,'R');
        $this->SetY($hH+8);
    }

    /*  FOOTER  */
    function Footer()
    {
        $this->SetY(-20);
        $this->draw(self::SLATE2);
        $this->SetLineWidth(0.3);
        $this->Line(18,$this->GetY(),$this->GetPageWidth()-18,$this->GetY());
        $this->Ln(3);
        $this->SetFont('Arial','',8);
        $this->rgb(self::SLATE5);
        $this->Cell(0,5,'Thank you for shopping with Borey.Store!',0,1,'C');
        $this->SetFont('Arial','',7.5);
        $this->Cell(0,4,chr(169).' '.date('Y').' Borey Store Co. Ltd | Phnom Penh, Cambodia',0,1,'C');
    }

    /*  INVOICE DETAILS  */
    function InvoiceDetails()
    {
        $inv=$this->invoice;
        $lx=18; $mid=105;
        $pageW=$this->GetPageWidth();
        $rColW=$pageW-18-$mid;

        $this->SetFont('Arial','B',7);
        $this->rgb(self::SLATE4);
        $this->SetXY($lx,$this->GetY());
        $this->Cell(80,5,'INVOICE TO',0,0,'L');
        $this->SetX($mid);
        $this->Cell($rColW,5,'INVOICE DETAILS',0,1,'L');
        $this->Ln(3);
        $startY=$this->GetY();

        // Left (labeled fields)
        $this->SetXY($lx,$startY);
        $this->SetFont('Arial','B',9);  $this->rgb(self::SLATE5); $this->Cell(80,5,'Name:',0,1,'L');
        $this->SetFont('Arial','B',13); $this->rgb(self::NAVY);   $this->SetX($lx); $this->Cell(80,7,$this->t($inv['customer_name']),0,1,'L');

        $this->SetFont('Arial','B',9);  $this->rgb(self::SLATE5); $this->SetX($lx); $this->Cell(80,5,'Address:',0,1,'L');
        $this->SetFont('Arial','',10);  $this->rgb(self::SLATE6); $this->SetX($lx); $this->MultiCell(80,5,$this->t($inv['customer_location']),0,'L');

        $this->SetFont('Arial','B',9);  $this->rgb(self::SLATE5); $this->SetX($lx); $this->Cell(80,5,'Phone Tel:',0,1,'L');
        $this->SetFont('Arial','',10);  $this->rgb(self::SLATE6); $this->SetX($lx); $this->Cell(80,6,$this->t($inv['customer_phone']),0,1,'L');

        // Right
        $ry=$startY; $labelW=36; $valW=$rColW-$labelW;
        $meta=[
            ['Invoice #:',$this->t($inv['invoice_number'])],
            ['Order #:',  $this->t($inv['order_number']??$inv['order_id'])],
            ['Date:',     date('M d, Y',strtotime($inv['created_at']))],
            ['Payment:',  $this->t($inv['payment_bank']??($inv['payment_method']??'Bakong'))],
        ];
        if(!empty($inv['bakong_hash']))
            $meta[]=['Bakong Hash:',substr($inv['bakong_hash'],0,8)];
        foreach($meta as $row){
            $this->SetXY($mid,$ry);
            $this->SetFont('Arial','B',9); $this->rgb(self::NAVY);
            $this->Cell($labelW,6,$row[0],0,0,'L');
            $this->SetFont('Arial','',9); $this->rgb(self::SLATE6);
            $this->Cell($valW,6,$row[1],0,0,'L');
            $ry+=6;
        }
        if(!empty($inv['payment_time'])){
            $this->SetXY($mid,$ry);
            $this->SetFont('Arial','B',9); $this->rgb(self::GREEN6);
            $this->Cell($labelW,6,'Paid:',0,0,'L');
            $this->SetFont('Arial','',9);
            $this->Cell($valW,6,date('M d, Y g:i A',strtotime($inv['payment_time'])),0,0,'L');
            $ry+=6;
        }
        $this->SetY(max($this->GetY(),$ry)+10);
    }

    /*  ITEMS TABLE  */
    function ItemsTable()
    {
        $items=json_decode($this->invoice['items'],true)?:[];
        $lx=18; $tw=$this->GetPageWidth()-36;
        $nameW=92;$qtyW=18;$priceW=32;$totW=$tw-$nameW-$qtyW-$priceW;

        // thead
        $this->fill(self::SLATE1); $this->draw(self::SLATE2);
        $this->SetLineWidth(0.3);
        $this->SetFont('Arial','B',8); $this->rgb(self::SLATE5);
        $this->SetX($lx);
        $this->Cell($nameW, 10,'ITEM', 'LTB',0,'L',true);
        $this->Cell($qtyW,  10,'QTY',  'TB', 0,'C',true);
        $this->Cell($priceW,10,'PRICE','TB', 0,'R',true);
        $this->Cell($totW,  10,'TOTAL','RTB',1,'R',true);

        foreach($items as $idx=>$item){
            $rawName  = (string)($item['name'] ?? '');
            $nameEn   = trim((string)($item['name_en'] ?? ''));
            $latinName = $this->t($rawName);
            $isNonLatin = ($rawName !== '' && trim($latinName) === '');
            // Priority: English name > Latin-transliterable name > category
            if ($nameEn !== '') {
                $name = $this->t($nameEn);
            } elseif (!$isNonLatin && $latinName !== '') {
                $name = $latinName;
            } else {
                $name = $this->t($item['category'] ?? 'Product');
            }
            if (trim($name) === '') $name = 'Product';
            $cat  =$this->t($item['category']??'');
            $qty  =intval($item['qty']??1);
            $price=floatval($item['price']??0);
            $total=$price*$qty;
            $rowH =$cat?15:10;

            if($this->GetY()+$rowH>$this->PageBreakTrigger){
                $this->AddPage();
                $this->fill(self::SLATE1);$this->draw(self::SLATE2);
                $this->SetFont('Arial','B',8);$this->rgb(self::SLATE5);
                $this->SetX($lx);
                $this->Cell($nameW, 10,'ITEM', 'LTB',0,'L',true);
                $this->Cell($qtyW,  10,'QTY',  'TB', 0,'C',true);
                $this->Cell($priceW,10,'PRICE','TB', 0,'R',true);
                $this->Cell($totW,  10,'TOTAL','RTB',1,'R',true);
            }

            $rowY=$this->GetY();
            $isLast=($idx===array_key_last($items));
            $bot=$isLast?'B':'';

            // resolve image
            $imgPath='';
            if(!empty($item['image'])){
                $cand=__DIR__.'/../../frontend/'.ltrim($item['image'],'/');
                if(file_exists($cand)){
                    $ext=strtolower(pathinfo($cand,PATHINFO_EXTENSION));
                    if(in_array($ext,['jpg','jpeg','png','gif']))$imgPath=$cand;
                }
            }

            // name cell background + border
            $this->draw(self::SLATE2);
            $this->SetFillColor(255,255,255);
            $this->Rect($lx,$rowY,$nameW,$rowH,'DF');

            // thumbnail
            $imgW=0;
            if($imgPath){
                $sz=$rowH-3;
                try{ $this->Image($imgPath,$lx+2,$rowY+1.5,$sz,$sz); $imgW=$sz+3; }
                catch(Exception $e){ $imgW=0; }
            }

            // name text
            $this->SetXY($lx+$imgW+2,$rowY+1.5);
            $this->SetFont('Arial','B',9.5); $this->rgb(self::NAVY);
            $this->Cell($nameW-$imgW-4,6,$name,0,0,'L');
            if($cat){
                $this->SetXY($lx+$imgW+2,$rowY+7.5);
                $this->SetFont('Arial','',7.5); $this->rgb(self::SLATE4);
                $this->Cell($nameW-$imgW-4,5,$cat,0,0,'L');
            }

            // other cells
            $this->SetXY($lx+$nameW,$rowY);
            $this->SetFont('Arial','B',9.5); $this->rgb(self::SLATE7);
            $this->Cell($qtyW,$rowH,$qty,'LR'.$bot,0,'C');
            $this->SetFont('Arial','',9.5); $this->rgb(self::SLATE6);
            $this->Cell($priceW,$rowH,'$'.number_format($price,2),'R'.$bot,0,'R');
            $this->SetFont('Arial','B',9.5); $this->rgb(self::NAVY);
            $this->Cell($totW,$rowH,'$'.number_format($total,2),'R'.$bot,1,'R');
        }
        $this->Ln(8);
    }

    /*  TOTALS  */
    function Totals()
    {
        $inv=$this->invoice;
        $pageW=$this->GetPageWidth();
        $lW=44;$vW=46;
        $lx=$pageW-18-$lW-$vW;

        $this->SetXY($lx,$this->GetY());
        $this->SetFont('Arial','',10); $this->rgb(self::SLATE6);
        $this->Cell($lW,7,'Subtotal',0,0,'R');
        $this->Cell($vW,7,'$'.number_format($inv['subtotal'],2),0,1,'R');

        $this->SetX($lx);
        $this->SetFont('Arial','',10); $this->rgb(self::SLATE6);
        $this->Cell($lW,7,'Delivery',0,0,'R');
        $fee=floatval($inv['delivery_fee']??0);
        $this->SetFont('Arial','B',10); $this->rgb(self::GREEN6);
        $this->Cell($vW,7,$fee>0?'$'.number_format($fee,2):'FREE',0,1,'R');

        $this->draw(self::SLATE2); $this->SetLineWidth(0.5);
        $this->Line($lx,$this->GetY()+1,$pageW-18,$this->GetY()+1);
        $this->SetLineWidth(0.2); $this->Ln(5);

        $this->SetX($lx);
        $this->SetFont('Arial','B',13); $this->rgb(self::NAVY);
        $this->Cell($lW,9,'Total (USD)',0,0,'R');
        $this->rgb(self::BLUE6);
        $this->Cell($vW,9,'$'.number_format($inv['total_usd'],2),0,1,'R');

        $this->SetX($lx);
        $this->SetFont('Arial','B',10); $this->rgb(self::SLATE5);
        $this->Cell($lW,7,'Total (KHR)',0,0,'R');
        $this->Cell($vW,7,number_format($inv['total_khr']).' Riel',0,1,'R');
        $this->Ln(10);
    }

    /*  PAYMENT INFO  */
    function PaymentInfo()
    {
        $inv=$this->invoice;
        $lx=18; $pw=$this->GetPageWidth()-36; $half=$pw/2;

        $this->draw(self::SLATE2); $this->SetLineWidth(0.4);
        $this->Line($lx,$this->GetY(),$lx+$pw,$this->GetY());
        $this->SetLineWidth(0.2); $this->Ln(6);

        $this->SetFont('Arial','B',7); $this->rgb(self::SLATE5);
        $this->SetX($lx);
        $this->Cell($half,5,'PAYMENT INFORMATION',0,0,'L');
        $this->Cell($half,5,'CONTACT SUPPORT',0,1,'R');
        $this->Ln(1);
        $this->SetFont('Arial','',9); $this->rgb(self::SLATE6);
        $pay=$this->t($inv['payment_bank']??($inv['payment_method']??'Bakong KHQR'));
        $this->SetX($lx);
        $this->Cell($half,5.5,'Paid via '.$pay,0,0,'L');
        $this->Cell($half,5.5,'Telegram: @monkey_Dluffy012',0,1,'R');
        $this->SetX($lx);
        $this->Cell($half,5.5,'Bakong KHQR - Khem Sovanny',0,0,'L');
        $this->Cell($half,5.5,'Phnom Penh, Cambodia',0,1,'R');
        if(!empty($inv['verified_sender'])){
            $this->Ln(2);
            $this->SetX($lx);
            $this->SetFont('Arial','B',9); $this->rgb(self::GREEN6);
            $this->Cell($pw,5,'Verified Sender: '.$this->t($inv['verified_sender']),0,1,'L');
        }
    }

    /*  RECEIPT PAGE  */
    function ReceiptSection()
    {
        $rp=$this->invoice['receipt_path']??'';
        if(empty($rp))return;
        $full=__DIR__.'/../'.ltrim($rp,'/');
        if(!file_exists($full))return;
        $this->AddPage();
        $this->SetFont('Arial','B',14); $this->rgb(self::NAVY);
        $this->Cell(0,10,'Payment Proof - Bank Transaction',0,1,'C');
        $this->SetFont('Arial','',9); $this->rgb(self::SLATE5);
        $this->Cell(0,6,'Invoice: '.$this->invoice['invoice_number'].' | Order: '.$this->invoice['order_id'],0,1,'C');
        $this->Ln(3);
        try{
            $info=getimagesize($full);
            if($info){
                $scale=min(170/$info[0],200/$info[1]);
                $sW=$info[0]*$scale;$sH=$info[1]*$scale;
                $ext=strtolower(pathinfo($full,PATHINFO_EXTENSION));
                if(in_array($ext,['jpg','jpeg','png','gif']))
                    $this->Image($full,(210-$sW)/2,$this->GetY(),$sW,$sH);
            }
        }catch(Exception $e){}
    }

    /*  GENERATE  */
    function Generate($output='I',$filename='')
    {
        $this->AddPage();
        $this->InvoiceDetails();
        $this->ItemsTable();
        $this->Totals();
        $this->PaymentInfo();
        $this->ReceiptSection();
        if(empty($filename))
            $filename='Invoice_'.$this->invoice['invoice_number'].'.pdf';
        return $this->Output($output,$filename);
    }
}

function generateInvoicePDF($invoice,$savePath=null)
{
    $pdf=new InvoicePDF($invoice);
    if($savePath){$pdf->Generate('F',$savePath);return $savePath;}
    $pdf->Generate('I','Invoice_'.$invoice['invoice_number'].'.pdf');
}

function downloadInvoicePDF($invoice)
{
    $pdf=new InvoicePDF($invoice);
    $pdf->Generate('D','Invoice_'.$invoice['invoice_number'].'.pdf');
}