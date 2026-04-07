<?php
// Load products from database for dynamic storefront
require_once '../backend/config/database.php';

$prod_query = mysqli_query($conn, "SELECT id, name, name_en, price, category, rating, image, stock, created_at, updated_at FROM products ORDER BY id DESC");
$products = [];
while ($row = mysqli_fetch_assoc($prod_query)) {
    // Fix image path: remap old img/products/ to new assets/img/products/
    if (!empty($row['image'])) {
        $row['image'] = preg_replace('#^img/products/#', 'assets/img/products/', $row['image']);
    }
    $products[] = $row;
}
$products_json = json_encode($products);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Borey.store | Premium Local Marketplace</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('storeApp', () => ({
                view: 'home',
                isMenuOpen: false,
                activeCategory: 'All',
                searchQuery: '',
                categories: ['All', 'Drinks', 'Water', 'Achoholic', 'Snacks'],
                bestSellerLimit: 6,
                cart: [],
                lastAddedCategory: '',
                products: <?php echo $products_json ?: '[]'; ?>.map(p => ({
                    ...p,
                    price: parseFloat(p.price) || 0,
                    rating: parseFloat(p.rating) || 5,
                    stock: parseInt(p.stock ?? 0)
                })),
                
                // Currency settings
                khrExchangeRate: 4100, // 1 USD = 4100 KHR
                
                // Payment state
                paymentLoading: false,
                paymentError: '',
                khqrImage: '',
                currentOrderId: '',
                currentMd5: '',
                paymentVerifying: false,
                paymentTimeoutMs: 10 * 60 * 1000,
                paymentExpiresAt: null,
                paymentSecondsLeft: 0,
                paymentTimeoutIntervalId: null,
                paymentPollIntervalId: null,
                paymentCompleted: false,
                showPaymentFailedModal: false,
                paymentFailedRedirectId: null,
                paymentStatusText: 'Processing...',
                paymentStatusType: 'processing',
                completedOrderId: '',
                invoiceNumber: '',
                invoiceLoading: false,
                
                // Delivery form state
                deliveryForm: {
                    name: '',
                    phone: '',
                    location: ''
                },
                deliveryCompleted: false,
                
                // Invoice popup state
                showInvoicePopup: false,

                // Payment method selection
                paymentMethod: 'khqr', // 'khqr' | 'cash'
                deliveryCityType: 'pp', // 'pp' | 'province'
                cashOrderTotal: 0,
                showCashModal: false,
                
                // Returns true if location looks like Phnom Penh
                isPhnomPenh() {
                    const loc = (this.deliveryForm.location || '').toLowerCase();
                    return loc.includes('phnom penh') ||
                           loc.includes('ភ្នំពេញ') ||
                           loc.includes('borey') ||
                           loc.includes('boeung') ||
                           loc.includes('daun penh') ||
                           loc.includes('toul kork') ||
                           loc.includes('chamkar mon') ||
                           loc.includes('sen sok') ||
                           loc.includes('meanchey') ||
                           loc.includes('pur senchey') ||
                           loc.includes('russei keo') ||
                           loc.includes('7 makara') ||
                           loc.includes('17 makara') ||
                           loc.includes('chbar ampov') ||
                           loc.includes('dangkao') ||
                           loc.includes('por senchey') ||
                           loc.includes('peng huoth') ||
                           loc.includes('chip mong') ||
                           loc.includes('orkide villa') ||
                           loc.includes('camko') ||
                           loc.includes('royal') ||
                           loc.includes('deyvann') ||
                           loc.includes('orkide');
                },

                filteredProducts() {
                    return this.products.filter(p => {
                        const matchCat = this.activeCategory === 'All' || p.category === this.activeCategory;
                        const matchSearch = p.name.toLowerCase().includes(this.searchQuery.toLowerCase());
                        return matchCat && matchSearch;
                    });
                },

                recommendedFromLastCategory() {
                    const fallbackCat = this.cart.length ? (this.cart[this.cart.length - 1].category || this.cart[0].category || '') : '';
                    const category = this.lastAddedCategory || fallbackCat;
                    if (!category) {
                        return [];
                    }

                    return this.products
                        .filter(p => p.category === category && (p.stock ?? 0) > 0)
                        .slice(0, 4);
                },

                bestSellers() {
                    return [...this.products]
                        .filter(p => (p.stock ?? 0) > 0)
                        .sort((a, b) => {
                            const soldA = parseInt(a.sold ?? 0) || 0;
                            const soldB = parseInt(b.sold ?? 0) || 0;
                            if (soldA !== soldB) return soldB - soldA;
                            const ratingA = parseFloat(a.rating ?? 0) || 0;
                            const ratingB = parseFloat(b.rating ?? 0) || 0;
                            if (ratingA !== ratingB) return ratingB - ratingA;
                            return (parseInt(b.stock ?? 0) || 0) - (parseInt(a.stock ?? 0) || 0);
                        });
                },

                addToCart(product) {
                    const found = this.cart.find(i => i.id === product.id);
                    const category = product.category || '';
                    if (found) {
                        const available = this.getStock(product.id);
                        if (available >= 0 && found.qty + 1 > available) {
                            this.stockError = `${product.name} only has ${available} left in stock.`;
                            return;
                        }
                        found.qty++;
                        this.lastAddedCategory = category;
                    } else {
                        const available = this.getStock(product.id);
                        if (available === 0) {
                            this.stockError = `${product.name} is out of stock.`;
                            return;
                        }
                        this.cart.push({ ...product, qty: 1 });
                        this.lastAddedCategory = category;
                    }
                    this.stockError = '';
                    // Toast simulation for mobile
                    this.showToast = true;
                    setTimeout(() => this.showToast = false, 2000);
                },

                scrollToBestSellers() {
                    const target = this.$refs.bestSellers;
                    if (target) {
                        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                },

                // Handle cash on delivery order
                async handleCashOrder() {
                    const orderId = 'BRY-CASH-' + Date.now();
                    const cartItems = [...this.cart];
                    const totalUsd = this.cartTotal;
                    const totalKhr = this.cartTotalKHR;
                    const customerName = this.deliveryForm.name;
                    const customerPhone = this.normalizePhone(this.deliveryForm.phone);
                    const customerLocation = this.deliveryForm.location;

                    this.cashOrderTotal = totalUsd;
                    this.deliveryCompleted = true;
                    this.completedOrderId = orderId;
                    this.showCashModal = true;

                    await this.generateInvoice(
                        orderId, customerName, customerPhone, customerLocation,
                        cartItems, totalUsd, totalKhr,
                        { payerName: customerName, paymentBank: 'Cash', payerAccountId: '', paymentTime: '', bakongHash: '' },
                        'Cash on Delivery'
                    );

                    this.cart = [];
                    this.deliveryForm = { name: '', phone: '', location: '' };
                    this.deliveryCompleted = false;
                    this.paymentMethod = 'khqr';
                    this.deliveryCityType = 'pp';
                    sessionStorage.removeItem('currentOrder');
                },

                removeFromCart(id) {
                    this.cart = this.cart.filter(i => i.id !== id);
                },

                updateQty(id, delta) {
                    const found = this.cart.find(i => i.id === id);
                    if (found) {
                        const nextQty = found.qty + delta;
                        if (nextQty < 1) {
                            this.removeFromCart(id);
                            return;
                        }
                        const available = this.getStock(id);
                        if (available >= 0 && nextQty > available) {
                            this.stockError = `${found.name} only has ${available} left in stock.`;
                            return;
                        }
                        found.qty = nextQty;
                        this.stockError = '';
                    }
                },

                get cartCount() {
                    return this.cart.reduce((a, b) => a + b.qty, 0);
                },

                get cartTotal() {
                    return this.cart.reduce((a, b) => a + (b.price * b.qty), 0);
                },
                
                get cartTotalKHR() {
                    return Math.round(this.cartTotal * this.khrExchangeRate);
                },
                showToast: false,
                stockError: '',

                getStock(productId) {
                    const p = this.products.find(pr => pr.id === productId);
                    return p ? parseInt(p.stock || 0) : 0;
                },

                getCartQty(productId) {
                    const item = this.cart.find(i => i.id === productId);
                    return item ? item.qty : 0;
                },

                stockLeft(productOrId) {
                    const id = typeof productOrId === 'object' ? productOrId.id : productOrId;
                    const base = this.getStock(id);
                    const inCart = this.getCartQty(id);
                    return Math.max(base - inCart, 0);
                },

                validateCartStock() {
                    const issues = [];
                    for (const item of this.cart) {
                        const available = this.getStock(item.id);
                        if (available >= 0 && item.qty > available) {
                            issues.push({ id: item.id, name: item.name, available });
                        }
                    }
                    return issues;
                },

                normalizePhone(rawPhone) {
                    const digits = String(rawPhone || '').replace(/\D+/g, '');
                    if (digits.startsWith('855')) {
                        return '0' + digits.slice(3);
                    }
                    if (digits.startsWith('0')) {
                        return digits;
                    }
                    return digits;
                },

                isValidPhone(rawPhone) {
                    const phone = this.normalizePhone(rawPhone);
                    return /^0\d{8,9}$/.test(phone);
                },
                
                // Generate KHQR code through Bakong API
                async generateKHQR(customerName, customerPhone, customerLocation) {
                    this.paymentLoading = true;
                    this.paymentError = '';

                    const phone = this.normalizePhone(customerPhone);
                    
                    const formData = new FormData();
                    formData.append('action', 'generate_khqr');
                    formData.append('amount', this.cartTotal.toFixed(2));
                    formData.append('order_id', 'BRY-' + Date.now());
                    formData.append('description', 'Borey Store - ' + this.cart.length + ' items');
                    
                    try {
                        const response = await fetch('../backend/api/payment.php', {
                            method: 'POST',
                            body: formData
                        });
                        
                        const result = await response.json();
                        
                        if (result.status === 'success') {
                            this.khqrImage = result.data.qr_image;
                            this.currentOrderId = result.data.order_id;
                            
                            // Store order details
                            sessionStorage.setItem('currentOrder', JSON.stringify({
                                orderId: result.data.order_id,
                                amount: result.data.amount,
                                customerName: customerName,
                                customerPhone: phone,
                                customerLocation: customerLocation,
                                items: this.cart,
                                timestamp: Date.now()
                            }));
                            
                            this.paymentLoading = false;
                            return true;
                        } else {
                            this.paymentError = result.message || 'Failed to generate payment code';
                            this.paymentLoading = false;
                            return false;
                        }
                    } catch (error) {
                        this.paymentError = 'Network error: ' + error.message;
                        this.paymentLoading = false;
                        return false;
                    }
                },
                
                startPaymentTimeout() {
                    this.stopPaymentTimeout();
                    this.paymentExpiresAt = Date.now() + this.paymentTimeoutMs;
                    this.paymentSecondsLeft = Math.ceil(this.paymentTimeoutMs / 1000);

                    this.paymentTimeoutIntervalId = setInterval(() => {
                        const remainingMs = this.paymentExpiresAt - Date.now();
                        this.paymentSecondsLeft = Math.max(0, Math.ceil(remainingMs / 1000));

                        if (remainingMs <= 0) {
                            this.stopPaymentTimeout();
                            this.handlePaymentTimeout();
                        }
                    }, 1000);
                },

                stopPaymentTimeout() {
                    if (this.paymentTimeoutIntervalId) {
                        clearInterval(this.paymentTimeoutIntervalId);
                        this.paymentTimeoutIntervalId = null;
                    }
                },

                startPaymentPolling() {
                    this.stopPaymentPolling();

                    // Poll every 3 seconds for faster payment detection
                    this.paymentPollIntervalId = setInterval(async () => {
                        if (this.paymentCompleted || this.paymentLoading || this.paymentVerifying || !this.showPaymentModal) {
                            return;
                        }

                        await this.verifyPayment(true);
                    }, 3000);
                },

                stopPaymentPolling() {
                    if (this.paymentPollIntervalId) {
                        clearInterval(this.paymentPollIntervalId);
                        this.paymentPollIntervalId = null;
                    }
                },

                get paymentTimeLeftText() {
                    const minutes = String(Math.floor(this.paymentSecondsLeft / 60)).padStart(2, '0');
                    const seconds = String(this.paymentSecondsLeft % 60).padStart(2, '0');
                    return `${minutes}:${seconds}`;
                },

                handlePaymentTimeout() {
                    if (this.paymentCompleted) {
                        return;
                    }

                    if (this.paymentFailedRedirectId) {
                        return;
                    }

                    this.stopPaymentPolling();
                    this.paymentVerifying = false;
                    this.paymentError = 'Payment not confirmed within 5 seconds. Returning to website...';
                    this.showPaymentModal = false;
                    this.khqrImage = '';
                    this.currentOrderId = '';
                    sessionStorage.removeItem('currentOrder');
                    this.showPaymentFailedModal = true;
                    this.paymentFailedRedirectId = setTimeout(() => {
                        window.location.href = 'index.php';
                    }, 2000);
                },

                handlePaymentSuccess(transaction = {}) {
                    if (this.paymentCompleted) {
                        return;
                    }

                    if (this.paymentFailedRedirectId) {
                        clearTimeout(this.paymentFailedRedirectId);
                        this.paymentFailedRedirectId = null;
                    }

                    // Update payment status to success
                    this.paymentStatusText = 'Payment Successful!';
                    this.paymentStatusType = 'success';

                    // Save order data before clearing
                    const orderData = JSON.parse(sessionStorage.getItem('currentOrder') || '{}');
                    const orderId = this.currentOrderId;
                    const cartItems = [...this.cart];
                    const totalUsd = this.cartTotal;
                    const totalKhr = this.cartTotalKHR;
                    const customerName = orderData.customerName || this.deliveryForm.name;
                    const customerPhone = this.normalizePhone(orderData.customerPhone || this.deliveryForm.phone);
                    const customerLocation = orderData.customerLocation || this.deliveryForm.location;

                    this.paymentCompleted = true;
                    this.stopPaymentTimeout();
                    this.stopPaymentPolling();
                    this.paymentError = '';
                    this.showPaymentModal = false;
                    this.showPaymentFailedModal = false;
                    this.completedOrderId = orderId;
                    this.khqrImage = '';
                    this.currentOrderId = '';
                    this.currentMd5 = '';
                    this.cart = [];
                    // Reset delivery form for next order
                    this.deliveryForm = { name: '', phone: '', location: '' };
                    this.deliveryCompleted = false;
                    this.paymentMethod = 'khqr';
                    this.deliveryCityType = 'pp';
                    sessionStorage.removeItem('currentOrder');
                    
                    // Show invoice popup instead of success view
                    this.showInvoicePopup = true;
                    
                    const payerAccountId = transaction.from_account || '';
                    const paymentBank = this.detectBankName(payerAccountId);
                    // Use the real full name returned by Bakong account lookup, fall back to parsing account ID
                    const payerName = transaction.payer_name || this.extractPayerName(payerAccountId);
                    const paymentTime = this.formatPaymentTimestamp(transaction.acknowledged_at || transaction.created_at);
                    const bakongHash = transaction.hash || '';

                    // Generate invoice asynchronously
                    this.generateInvoice(orderId, customerName, customerPhone, customerLocation, cartItems, totalUsd, totalKhr, {
                        payerName,
                        paymentBank,
                        payerAccountId,
                        paymentTime,
                        bakongHash,
                    });
                },
                
                // Generate invoice for completed order
                async generateInvoice(orderId, customerName, customerPhone, customerLocation, items, totalUsd, totalKhr, paymentDetails = {}, overrideMethod = '') {
                    this.invoiceLoading = true;

                    const phone = this.normalizePhone(customerPhone);
                    if (!this.isValidPhone(phone)) {
                        console.error('Invoice generation blocked: invalid phone number');
                        this.invoiceLoading = false;
                        return;
                    }
                    
                    const formData = new FormData();
                    formData.append('action', 'create');
                    formData.append('order_id', orderId);
                    formData.append('customer_name', customerName);
                    formData.append('customer_phone', phone);
                    formData.append('customer_location', customerLocation);
                    formData.append('items', JSON.stringify(items));
                    formData.append('subtotal', totalUsd.toFixed(2));
                    formData.append('delivery_fee', '0');
                    formData.append('total_usd', totalUsd.toFixed(2));
                    formData.append('total_khr', totalKhr.toString());
                    formData.append('payment_method', overrideMethod || 'Bakong KHQR');
                    formData.append('payment_bank', paymentDetails.paymentBank || 'Bakong');
                    formData.append('payer_name', paymentDetails.payerName || customerName || 'Unknown');
                    formData.append('payer_account_id', paymentDetails.payerAccountId || '');
                    formData.append('payment_time', paymentDetails.paymentTime || '');
                    formData.append('bakong_hash', paymentDetails.bakongHash || '');
                    
                    try {
                        const response = await fetch('../backend/api/invoice.php', {
                            method: 'POST',
                            body: formData
                        });
                        
                        const result = await response.json();
                        
                        if (result.status === 'success') {
                            this.invoiceNumber = result.data.invoice_number;
                        } else {
                            this.stockError = result.message || 'Unable to create invoice';
                            console.error('Invoice creation failed:', this.stockError);
                        }
                    } catch (error) {
                        console.error('Invoice generation error:', error);
                    }
                    
                    this.invoiceLoading = false;
                },

                detectBankName(accountId) {
                    const bankCode = (accountId.split('@')[1] || '').toLowerCase();
                    const bankMap = {
                        abaa: 'ABA Bank',
                        aclb: 'ACLEDA Bank',
                        wing: 'Wing Bank',
                        trnb: 'TrueMoney',
                        cana: 'Canadia Bank',
                        pcbc: 'PPCBank',
                        ftbl: 'Foreign Trade Bank',
                        sucb: 'Sathapana Bank',
                        cimb: 'CIMB Bank',
                    };

                    if (bankMap[bankCode]) {
                        return bankMap[bankCode];
                    }

                    return bankCode ? bankCode.toUpperCase() : 'Bakong';
                },

                extractPayerName(accountId) {
                    if (!accountId) {
                        return this.deliveryForm.name || 'Unknown';
                    }

                    const localPart = accountId.split('@')[0] || '';
                    if (!localPart) {
                        return accountId;
                    }

                    if (localPart.includes('xxx')) {
                        return accountId;
                    }

                    return localPart
                        .replace(/[._-]+/g, ' ')
                        .replace(/\b\w/g, (char) => char.toUpperCase())
                        .trim();
                },

                formatPaymentTimestamp(timestampMs) {
                    const numericValue = Number(timestampMs);
                    if (!numericValue || Number.isNaN(numericValue)) {
                        return '';
                    }

                    const date = new Date(numericValue);
                    const pad = (value) => String(value).padStart(2, '0');
                    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
                },
                
                // Confirm payment - verifies with Bakong API first
                async confirmPaymentManually() {
                    if (!this.currentOrderId) {
                        this.paymentError = 'No active order';
                        return;
                    }
                    
                    this.paymentVerifying = true;
                    this.paymentError = '';
                    
                    try {
                        // First, check if payment is confirmed by Bakong API
                        const formData = new FormData();
                        formData.append('action', 'check_payment');
                        formData.append('order_id', this.currentOrderId);
                        
                        const response = await fetch('../backend/api/payment.php', {
                            method: 'POST',
                            body: formData
                        });
                        
                        const result = await response.json();
                        
                        if (result.status === 'success' && result.data.status === 'completed') {
                            // Payment confirmed by Bakong API
                            this.handlePaymentSuccess(result.data.transaction || {});
                        } else {
                            // Payment not yet confirmed by Bakong
                            this.paymentError = 'Payment not yet received. Please complete the payment in your Bakong app first, then try again.';
                        }
                    } catch (error) {
                        this.paymentError = 'Error verifying payment: ' + error.message;
                    }
                    
                    this.paymentVerifying = false;
                },

                // Verify payment received
                async verifyPayment(silent = false) {
                    if (!this.currentOrderId) {
                        if (!silent) {
                            this.paymentError = 'No active order';
                        }
                        return false;
                    }

                    if (this.paymentExpiresAt && Date.now() > this.paymentExpiresAt) {
                        this.handlePaymentTimeout();
                        return false;
                    }
                    
                    this.paymentVerifying = true;
                    
                    const formData = new FormData();
                    formData.append('action', 'check_payment');
                    formData.append('order_id', this.currentOrderId);
                    
                    try {
                        const response = await fetch('../backend/api/payment.php', {
                            method: 'POST',
                            body: formData
                        });
                        
                        const result = await response.json();
                        this.paymentVerifying = false;
                        
                        if (result.data.status === 'completed') {
                            this.handlePaymentSuccess(result.data.transaction || {});
                            return true;
                        } else {
                            if (!silent) {
                                this.paymentError = 'Payment not yet confirmed. Please try again.';
                            }
                            return false;
                        }
                    } catch (error) {
                        if (!silent) {
                            this.paymentError = 'Error verifying payment: ' + error.message;
                        }
                        this.paymentVerifying = false;
                        return false;
                    }
                },
                
                // Proceed to payment after delivery details filled
                proceedToPayment() {
                    if (!this.deliveryForm.name || !this.deliveryForm.phone || !this.deliveryForm.location) {
                        alert('Please fill in all delivery details first');
                        return;
                    }

                    const stockIssues = this.validateCartStock();
                    if (stockIssues.length > 0) {
                        const first = stockIssues[0];
                        this.stockError = `${first.name} only has ${first.available} left in stock.`;
                        alert(this.stockError);
                        return;
                    }

                    const normalizedPhone = this.normalizePhone(this.deliveryForm.phone);
                    if (!this.isValidPhone(normalizedPhone)) {
                        alert('Please enter a valid phone number (e.g., 0967900198).');
                        return;
                    }
                    this.deliveryForm.phone = normalizedPhone;

                    // Cash on delivery flow
                    if (this.paymentMethod === 'cash') {
                        if (this.deliveryCityType !== 'pp') {
                            alert('Cash on delivery is only available for Phnom Penh orders.');
                            this.paymentMethod = 'khqr';
                            return;
                        }
                        this.handleCashOrder();
                        return;
                    }
                    
                    // Mark delivery as completed
                    this.deliveryCompleted = true;
                    
                    // Reset payment status
                    this.paymentStatusText = 'Processing...';
                    this.paymentStatusType = 'processing';
                    
                    // Show payment modal and generate KHQR
                    this.stopPaymentTimeout();
                    this.stopPaymentPolling();
                    this.paymentCompleted = false;
                    this.showPaymentModal = true;
                    this.generateKHQRModal(this.deliveryForm.name, this.deliveryForm.phone, this.deliveryForm.location);
                },
                
                // Generate KHQR from modal using JavaScript gateway
                async generateKHQRModal(customerName, customerPhone, customerLocation) {
                    this.paymentLoading = true;
                    this.paymentError = '';

                    const phone = this.normalizePhone(customerPhone);
                    if (!this.isValidPhone(phone)) {
                        this.paymentLoading = false;
                        this.paymentError = 'Please enter a valid phone number (e.g., 0967900198).';
                        return;
                    }
                    
                    const orderId = 'BRY-' + Date.now();
                    const description = `Borey Store - ${this.cart.length} items`;
                    // Send amount in KHR for Bakong payment
                    const amountKHR = this.cartTotalKHR;
                    
                    try {
                        // Call server-side PHP endpoint to generate KHQR
                        const formData = new FormData();
                        formData.append('action', 'generate_khqr');
                        formData.append('amount', amountKHR);
                        formData.append('order_id', orderId);
                        formData.append('description', description);
                        formData.append('customer_name', customerName);
                        formData.append('customer_phone', phone);
                        formData.append('customer_location', customerLocation);
                        
                        const response = await fetch('../backend/api/payment.php', {
                            method: 'POST',
                            body: formData
                        });
                        
                        const result = await response.json();
                        
                        if (result.status === 'success' && result.data.qr_image) {
                            this.khqrImage = result.data.qr_image;
                            this.currentOrderId = result.data.order_id;
                            this.currentMd5 = result.data.md5 || '';
                            this.paymentCompleted = false;
                            this.paymentStatusText = 'Processing...';
                            this.paymentStatusType = 'processing';
                            this.startPaymentTimeout();
                            // Enable automatic polling to detect payment
                            this.startPaymentPolling();
                            
                            sessionStorage.setItem('currentOrder', JSON.stringify({
                                orderId: result.data.order_id,
                                amount: result.data.amount,
                                khqr: result.data.khqr,
                                customerName: customerName,
                                customerPhone: phone,
                                customerLocation: customerLocation,
                                items: this.cart,
                                timestamp: Date.now()
                            }));
                            
                            this.paymentLoading = false;
                        } else {
                            this.stopPaymentTimeout();
                            this.stopPaymentPolling();
                            this.paymentError = result.message || 'Failed to generate payment code';
                            this.paymentLoading = false;
                        }
                    } catch (error) {
                        this.stopPaymentTimeout();
                        this.stopPaymentPolling();
                        this.paymentError = 'Network error: ' + (error.message || 'KHQR generation failed');
                        console.error('KHQR Error:', error);
                        this.paymentLoading = false;
                    }
                },
                
                // Store transaction in database
                async storeTransaction(data) {
                    try {
                        const formData = new FormData();
                        formData.append('action', 'store_transaction');
                        formData.append('order_id', data.order_id);
                        formData.append('amount', data.amount);
                        formData.append('khqr', data.khqr);
                        formData.append('customer_name', data.customer_name);
                        formData.append('customer_phone', data.customer_phone);
                        formData.append('customer_location', data.customer_location);
                        
                        const response = await fetch('../backend/api/payment.php', {
                            method: 'POST',
                            body: formData
                        });
                        
                        const result = await response.json();
                        console.log('Transaction stored:', result);
                    } catch (error) {
                        console.error('Transaction storage error:', error);
                    }
                },
                
                showPaymentModal: false,
            }));
        });
    </script>
    <script src="../backend/api/bakong-payment.js"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Bayon&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; -webkit-tap-highlight-color: transparent; }
        .ocean-forest-bg {
            background-color: #c9d8e8;
            background-image:
                radial-gradient(circle at 15% 10%, rgba(10, 34, 58, 0.55) 0%, rgba(10, 34, 58, 0) 28%),
                radial-gradient(circle at 85% 12%, rgba(18, 52, 86, 0.52) 0%, rgba(18, 52, 86, 0) 30%),
                radial-gradient(circle at 50% 78%, rgba(36, 93, 147, 0.35) 0%, rgba(36, 93, 147, 0) 42%),
                linear-gradient(180deg, #0f2a45 0%, #1e4f7f 38%, #5b8fbe 68%, #c5d7e8 100%);
            background-attachment: fixed;
        }
        :lang(km) { font-family: 'Bayon', sans-serif; }
        .product-name-outline {
            -webkit-text-stroke: 0;
            text-shadow: 0.2px 0.2px 0 rgba(0, 0, 0, 0.45);
        }
        .force-black-text,
        .force-black-text * {
            color: #000 !important;
        }
        .force-black-text .allow-light-text,
        .force-black-text .allow-light-text * {
            color: #fff !important;
        }
        .force-black-text input::placeholder,
        .force-black-text textarea::placeholder {
            color: rgba(0, 0, 0, 0.6) !important;
        }
        [x-cloak] { display: none !important; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .khqr-pattern { background-image: radial-gradient(#000 1px, transparent 1px); background-size: 8px 8px; }
        @keyframes progressPulse {
            0%, 100% { opacity: 0.6; transform: scaleX(0.3); transform-origin: left; }
            50% { opacity: 1; transform: scaleX(1); transform-origin: left; }
        }
    </style>
</head>
<body class="ocean-forest-bg text-slate-900 overflow-x-hidden" x-data="storeApp" x-cloak>

    <!-- Mobile Navigation Drawer -->
    <div x-show="isMenuOpen" 
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="-translate-x-full"
         x-transition:enter-end="translate-x-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="translate-x-0"
         x-transition:leave-end="-translate-x-full"
         class="fixed inset-0 z-[60] bg-white w-4/5 shadow-2xl lg:hidden">
        <div class="p-6 flex flex-col h-full">
            <div class="flex justify-between items-center mb-10">
                <span class="text-xl font-extrabold italic">Borey<span class="text-blue-600">.store</span></span>
                <button @click="isMenuOpen = false" class="p-2"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>
            </div>
            <nav class="flex flex-col space-y-6 text-xl font-bold">
                <template x-for="cat in categories">
                    <button @click="activeCategory = cat; view = 'home'; isMenuOpen = false" 
                            x-text="cat" 
                            class="text-left py-2 border-b border-slate-50"
                            :class="activeCategory === cat ? 'text-blue-600' : 'text-slate-700'"></button>
                </template>
            </nav>
            <div class="mt-auto pt-10 border-t">
                <p class="text-xs text-slate-400 font-bold uppercase tracking-widest mb-4 text-center">Contact Us</p>
                <div class="flex justify-center gap-6">
                    <div class="w-10 h-10 bg-slate-100 rounded-full flex items-center justify-center text-blue-600"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/><path d="m22 2-7 20-4-9-9-4Z"/></svg></div>
                    <div class="w-10 h-10 bg-slate-100 rounded-full flex items-center justify-center text-slate-600"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="20" x="2" y="2" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" x2="17.51" y1="6.5" y2="6.5"/></svg></div>
                </div>
            </div>
        </div>
    </div>
    <div x-show="isMenuOpen" @click="isMenuOpen = false" class="fixed inset-0 bg-black/20 backdrop-blur-sm z-[55] lg:hidden"></div>

    <!-- Desktop & Mobile Header -->
    <nav class="sticky top-0 z-50 bg-white/80 backdrop-blur-lg border-b border-slate-100 w-full">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex items-center justify-between">
            <div class="flex items-center gap-2 md:gap-4">
                <button @click="isMenuOpen = true" class="lg:hidden p-2 text-slate-700 active:bg-slate-100 rounded-lg">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/></svg>
                </button>
                <h1 @click="view = 'home'; window.scrollTo(0,0)" class="text-xl md:text-2xl font-black tracking-tighter cursor-pointer flex items-center">
                    BOREY<span class="text-blue-600">.STORE</span>
                </h1>
            </div>

            <div class="hidden lg:flex items-center space-x-8 text-sm font-bold text-slate-500 uppercase tracking-wide">
                <template x-for="cat in categories">
                    <button @click="activeCategory = cat; view = 'home'" x-text="cat" 
                            class="hover:text-blue-600 transition-colors"
                            :class="activeCategory === cat ? 'text-blue-600' : ''"></button>
                </template>
            </div>

            <div class="flex items-center gap-2 md:gap-4">
                <div class="hidden sm:flex items-center bg-slate-100 rounded-2xl px-3 py-2 focus-within:ring-2 focus-within:ring-blue-500 transition-all">
                    <svg class="text-slate-400" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    <input type="text" x-model="searchQuery" placeholder="Search products..." class="bg-transparent border-none focus:outline-none text-xs ml-2 w-32 xl:w-48">
                </div>
                <button @click="view = 'cart'" class="relative p-2.5 bg-slate-900 text-white rounded-2xl shadow-lg shadow-slate-900/20 active:scale-90 transition-transform">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                    <span x-show="cartCount > 0" x-text="cartCount" class="absolute -top-1 -right-1 bg-blue-500 text-white text-[10px] font-bold h-5 w-5 rounded-full flex items-center justify-center border-2 border-white"></span>
                </button>
            </div>
        </div>
        <div class="sm:hidden px-4 pb-4">
            <div class="flex items-center bg-slate-100 rounded-2xl px-3 py-2 focus-within:ring-2 focus-within:ring-blue-500 transition-all">
                <svg class="text-slate-400" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <input type="text" x-model="searchQuery" placeholder="Search products..." class="bg-transparent border-none focus:outline-none text-sm ml-2 w-full">
            </div>
        </div>
    </nav>

    <!-- Main Views -->
    <main class="w-full">
        
        <!-- Home View -->
        <div x-show="view === 'home'" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 md:py-10">
            <!-- Fluid Hero Section -->
            <div class="relative overflow-hidden bg-slate-900 text-white rounded-[1.5rem] md:rounded-[3rem] p-4 md:p-16 mb-6 md:mb-12 min-h-[320px] md:min-h-[400px] flex items-center">
                <div class="absolute inset-0 z-0">
                    <img src="assets/img/Hero2.jpg" class="w-full h-full object-cover opacity-90 brightness-110 scale-105" alt="Hero background">
                    <div class="absolute inset-0 bg-gradient-to-r from-slate-900/65 via-slate-900/45 to-transparent"></div>
                </div>
                <div class="relative z-10 max-w-2xl">
                    <div class="inline-flex items-center gap-2 px-3 py-1 bg-blue-600 rounded-full text-[9px] md:text-[10px] font-black uppercase tracking-[0.2em] mb-4 md:mb-6">
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-white"></span>
                        </span>
                        New in Stock
                    </div>
                    <h2 class="text-2xl md:text-5xl lg:text-6xl font-black mb-3 md:mb-4 leading-[1.1]">Elevate Your living style with Borey Store</h2>
                    <p class="text-slate-300 text-xs md:text-lg mb-6 md:mb-8 max-w-lg leading-relaxed">All Staffs is here, At Borey is a Modern Local Store.</p>
                    <div class="flex flex-wrap gap-3">
                        <button @click="activeCategory = 'All'; window.scrollTo({top: 800, behavior: 'smooth'})" class="bg-white text-slate-900 px-5 md:px-6 py-3 md:py-3.5 rounded-2xl font-extrabold text-xs md:text-sm hover:bg-blue-50 transition-colors shadow-xl">Start Exploring</button>
                        <button @click="scrollToBestSellers()" class="bg-white/10 backdrop-blur-md text-white border border-white/20 px-5 md:px-6 py-3 md:py-3.5 rounded-2xl font-extrabold text-xs md:text-sm hover:bg-white/20 transition-colors">See Best Sellers</button>
                    </div>
                </div>
            </div>

            <!-- Best Sellers Section -->
            <div x-ref="bestSellers" class="bg-white/70 backdrop-blur-lg border border-slate-100 rounded-[2rem] p-4 md:p-6 mb-8 shadow-lg shadow-blue-100/40">
                <div class="flex items-center justify-between mb-4 md:mb-6">
                    <div>
                        <p class="text-[10px] font-black text-blue-600 uppercase tracking-[0.22em]">Top Picks</p>
                        <h3 class="text-xl md:text-2xl font-black">Best Sellers</h3>
                        <p class="text-[12px] text-slate-500 font-semibold">Based on purchases and ratings</p>
                    </div>
                    <span class="text-[10px] font-bold text-slate-500 uppercase tracking-[0.2em]" x-text="'Showing ' + Math.min(bestSellers().length, bestSellerLimit) + ' items'"></span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-4">
                    <template x-for="(product, idx) in bestSellers().slice(0, bestSellerLimit)" :key="'best-' + product.id">
                        <div class="relative bg-blue-50/60 border border-blue-100 rounded-2xl p-3 flex gap-3 items-start shadow-sm">
                            <div class="absolute -top-2 -left-2 bg-blue-600 text-white text-[10px] font-black px-2 py-1 rounded-xl shadow">#<span x-text="idx + 1"></span></div>
                            <div class="w-20 h-20 rounded-xl overflow-hidden bg-white flex-shrink-0">
                                <img :src="product.image" class="w-full h-full object-cover" loading="lazy">
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 text-[10px] font-bold text-slate-500 mb-1">
                                    <span x-text="product.category"></span>
                                    <span class="text-yellow-500">★</span><span x-text="product.rating"></span>
                                </div>
                                <p class="font-black text-sm text-slate-900 truncate" x-text="product.name"></p>
                                <p class="text-[11px] font-bold text-slate-500 mt-1" x-text="stockLeft(product) > 0 ? (stockLeft(product) + ' in stock') : 'Out of stock'"></p>
                                <div class="mt-2 flex items-center justify-between">
                                    <span class="text-base font-black text-amber-600">$<span x-text="product.price.toFixed(2)"></span></span>
                                    <button @click="addToCart(product)"
                                            :disabled="stockLeft(product) <= 0"
                                            class="px-3 py-2 rounded-xl text-xs font-black transition-all"
                                            :class="stockLeft(product) <= 0
                                                ? 'bg-slate-100 text-slate-400 cursor-not-allowed'
                                                : 'bg-blue-600 text-white hover:bg-blue-700 shadow-sm'">
                                        Add
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Categories Scroll (Mobile Only) -->
            <div class="lg:hidden flex overflow-x-auto gap-2 no-scrollbar mb-8 pb-2">
                <template x-for="cat in categories">
                    <button @click="activeCategory = cat" 
                            x-text="cat"
                            class="whitespace-nowrap px-5 py-2.5 rounded-xl text-xs font-bold border transition-all"
                            :class="activeCategory === cat ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-600 border-slate-200'"></button>
                </template>
            </div>

            <!-- Responsive Product Grid -->
            <div class="flex items-center justify-between mb-8 px-1">
                <h3 class="text-xl md:text-2xl font-black tracking-tight" x-text="activeCategory === 'All' ? 'Curated Collection' : activeCategory"></h3>
                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest" x-text="filteredProducts().length + ' items'"></span>
            </div>

            <div class="grid grid-cols-3 sm:grid-cols-3 lg:grid-cols-3 xl:grid-cols-4 gap-4 md:gap-6">
                <template x-for="product in filteredProducts()" :key="product.id">
                    <div class="group bg-blue-50/55 backdrop-blur-md rounded-[2rem] p-3 md:p-4 border border-blue-200/70 hover:border-blue-300/90 shadow-lg shadow-blue-100/40 transition-all flex flex-col h-full">
                        <div class="relative aspect-square w-full rounded-[1.5rem] overflow-hidden mb-4">
                            <img :src="product.image" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                            <div class="absolute top-2 right-2 bg-white/90 backdrop-blur px-2 py-1 rounded-full flex items-center gap-1 text-[8px] sm:text-[9px] md:text-[10px] font-bold shadow-sm">
                                <span class="text-yellow-500 text-[8px] sm:text-[9px] md:text-[10px] leading-none">★</span> <span class="text-[8px] sm:text-[9px] md:text-[10px] leading-none" x-text="product.rating"></span>
                            </div>
                            <div class="absolute bottom-2 left-2 px-2 py-1 rounded-full text-[8px] sm:text-[9px] font-bold shadow-sm"
                                 :class="stockLeft(product) > 0 ? 'bg-white/90 text-green-700' : 'bg-slate-200/90 text-slate-500'"
                                 x-text="stockLeft(product) > 0 ? (stockLeft(product) + ' in stock') : 'Out of stock'"></div>
                        </div>
                        <div class="px-1 flex-1">
                            <p class="text-[8px] sm:text-[9px] md:text-[10px] font-extrabold text-blue-600 uppercase tracking-widest mb-1" x-text="product.category"></p>
                            <h4 class="font-bold text-[10px] sm:text-xs md:text-base lg:text-lg mb-4 text-green-600 leading-tight line-clamp-2 product-name-outline" x-text="product.name"></h4>
                        </div>
                        <div class="mt-auto flex items-center justify-between p-1">
                            <span class="inline-block text-[11px] sm:text-sm md:text-lg lg:text-xl font-black bg-gradient-to-r from-yellow-400 via-amber-500 to-yellow-600 bg-clip-text text-transparent leading-none drop-shadow-[0_1px_1px_rgba(120,53,15,0.25)] transition-all duration-200 group-hover:scale-105 group-hover:drop-shadow-[0_2px_4px_rgba(120,53,15,0.25)]">$<span x-text="product.price.toFixed(2)"></span></span>
                            <button @click="addToCart(product)"
                                    :disabled="stockLeft(product) <= 0"
                                    class="p-3 rounded-2xl transition-all active:scale-90"
                                    :class="stockLeft(product) <= 0
                                        ? 'bg-slate-100 text-slate-400 cursor-not-allowed'
                                        : 'bg-slate-100 text-slate-900 hover:bg-blue-600 hover:text-white'">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- Cart View -->
        <div x-show="view === 'cart'" class="max-w-3xl mx-auto px-4 sm:px-6 py-8 md:py-16 force-black-text">
            <button @click="view = 'home'" class="flex items-center text-slate-500 font-extrabold mb-8 text-sm group">
                <div class="p-2 bg-slate-100 rounded-xl mr-3 group-hover:bg-slate-200 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                </div>
                Continue Shopping
            </button>
            <h2 class="text-3xl md:text-4xl font-black mb-8">Your Bag <span class="text-slate-300" x-text="'(' + cartCount + ')'"></span></h2>
            <template x-if="stockError">
                <div class="mb-4 p-4 rounded-2xl bg-red-50 border border-red-200 text-red-700 font-bold text-sm">
                    <div class="flex items-center gap-2">
                        <span aria-hidden="true">✖</span>
                        <span x-text="stockError"></span>
                    </div>
                </div>
            </template>

            <div x-show="cart.length === 0" class="text-center py-20 bg-white/35 backdrop-blur-xl rounded-[2.5rem] border border-white/60 px-6">
                <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-6 text-slate-300">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                </div>
                <p class="text-slate-400 font-bold mb-8">Your shopping bag is currently empty.</p>
                <button @click="view = 'home'" class="bg-blue-500 hover:bg-blue-400 allow-light-text px-10 py-4 rounded-2xl font-black shadow-xl shadow-blue-400/40 transition-colors">Back to Store</button>
            </div>

            <div x-show="cart.length > 0" class="space-y-4">
                <template x-for="item in cart" :key="item.id">
                    <div class="bg-white/35 backdrop-blur-lg p-4 rounded-[2rem] border border-white/60 flex gap-4 md:gap-6 items-center">
                        <img :src="item.image" class="w-20 h-20 md:w-28 md:h-28 object-cover rounded-[1.5rem]">
                        <div class="flex-1 flex flex-col min-w-0">
                            <div class="flex justify-between items-start mb-2">
                                <h4 class="font-black text-sm md:text-lg truncate" x-text="item.name"></h4>
                                <button @click="removeFromCart(item.id)" class="text-slate-300 hover:text-red-500 p-1"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>
                            </div>
                            <div class="flex justify-between items-center mt-auto">
                                <div class="flex items-center gap-3 bg-slate-50 p-1 rounded-xl">
                                    <button @click="updateQty(item.id, -1)" class="w-8 h-8 bg-white rounded-lg shadow-sm font-black text-slate-900">-</button>
                                    <span class="font-black text-sm w-4 text-center" x-text="item.qty"></span>
                                    <button @click="updateQty(item.id, 1)" class="w-8 h-8 bg-white rounded-lg shadow-sm font-black text-slate-900">+</button>
                                </div>
                                <span class="font-black text-base md:text-lg text-slate-900">$<span x-text="(item.price * item.qty).toFixed(2)"></span></span>
                            </div>
                        </div>
                    </div>
                </template>

                <div class="mt-12 bg-white/35 backdrop-blur-xl text-white p-8 md:p-10 rounded-[2.5rem] shadow-2xl border border-white/60">
                    <div class="space-y-4 mb-10">
                        <div class="flex justify-between text-slate-400 font-bold text-sm uppercase tracking-widest"><span>Subtotal</span> <span x-text="'$' + cartTotal.toFixed(2)"></span></div>
                        <div class="flex justify-between text-slate-400 font-bold text-sm uppercase tracking-widest"><span>Delivery</span> <span class="text-blue-400">FREE</span></div>
                        <div class="pt-6 border-t border-slate-800 flex justify-between text-2xl md:text-3xl font-black"><span>Total</span> <span class="text-blue-400" x-text="'$' + cartTotal.toFixed(2)"></span></div>
                    </div>
                    <button @click="view = 'checkout'" class="w-full bg-blue-600 hover:bg-blue-700 py-5 rounded-[1.5rem] font-black text-lg transition-all active:scale-[0.98] shadow-xl shadow-blue-500/20">Proceed to Checkout</button>
                    <div x-show="recommendedFromLastCategory().length" class="mt-8 bg-white/80 text-slate-900 border border-slate-100 rounded-[1.75rem] p-4 md:p-5 shadow-lg">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.22em]">More from this category</p>
                                <p class="text-lg font-black" x-text="lastAddedCategory || 'Suggested for you'"></p>
                            </div>
                            <span class="text-[10px] font-black text-blue-600 uppercase tracking-[0.25em]">Picked for you</span>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <template x-for="rec in recommendedFromLastCategory()" :key="'rec-below-' + rec.id">
                                <div class="flex items-center gap-3 bg-white border border-slate-100 rounded-[1.25rem] p-3 shadow-sm">
                                    <div class="w-14 h-14 rounded-xl overflow-hidden flex-shrink-0 bg-slate-50">
                                        <img :src="rec.image" class="w-full h-full object-cover" loading="lazy">
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-[9px] font-extrabold text-blue-600 uppercase tracking-widest" x-text="rec.category"></p>
                                        <p class="text-sm font-black text-slate-900 truncate" x-text="rec.name"></p>
                                        <p class="text-xs font-bold text-slate-500">$<span x-text="rec.price.toFixed(2)"></span></p>
                                    </div>
                                    <button @click="addToCart(rec)"
                                            :disabled="stockLeft(rec) <= 0"
                                            class="h-10 min-w-[2.75rem] px-3 rounded-full font-black flex items-center justify-center shadow-sm transition-all"
                                            :class="stockLeft(rec) <= 0
                                                ? 'bg-slate-100 text-slate-400 border border-slate-200 cursor-not-allowed'
                                                : 'bg-white text-slate-900 border border-slate-200 hover:bg-blue-50 hover:border-blue-300 active:scale-95'"
                                            title="Add to cart">
                                        +
                                    </button>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Checkout View (Responsive Grid) -->
        <div x-show="view === 'checkout'" class="max-w-6xl mx-auto px-4 sm:px-6 py-8 md:py-16 force-black-text">
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-10">
                <div class="lg:col-span-7">
                    <!-- Delivery Details Section -->
                    <div>
                        <h2 class="text-3xl font-black mb-8 flex items-center gap-4">
                            <span class="w-10 h-10 rounded-2xl flex items-center justify-center text-sm font-black text-white shadow-md" :class="deliveryCompleted ? 'bg-green-400' : 'bg-blue-500'">
                                <span x-show="!deliveryCompleted">1</span>
                                <span x-show="deliveryCompleted">✓</span>
                            </span>
                            Delivery Details
                            <span x-show="deliveryCompleted" class="text-sm font-bold text-green-600 ml-auto">Confirmed</span>
                        </h2>

                        <!-- Delivery Form (shown when not completed) -->
                        <div x-show="!deliveryCompleted" class="space-y-4 mb-10 bg-white/35 backdrop-blur-xl border border-white/60 rounded-[2rem] p-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="space-y-1.5 col-span-1 md:col-span-2">
                                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Full Name</label>
                                    <input type="text" x-model="deliveryForm.name" placeholder="Your Name" class="w-full p-4 bg-white border border-slate-200 rounded-2xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all">
                                </div>
                                <div class="space-y-1.5 col-span-1 md:col-span-2">
                                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Address</label>
                                    <input type="text" x-model="deliveryForm.location" placeholder="Your current location" class="w-full p-4 bg-white border border-slate-200 rounded-2xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all">
                                </div>
                                <div class="space-y-1.5 flex-1">
                                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Phone</label>
                                    <div class="flex gap-2">
                                        <span class="bg-slate-100 px-4 py-4 rounded-2xl font-black text-slate-500">+855</span>
                                        <input type="tel" x-model="deliveryForm.phone" placeholder="Your Phone Number" inputmode="numeric" pattern="0[0-9]{8,9}" maxlength="10" title="Use 0 followed by 8-9 digits" class="flex-1 p-4 bg-white border border-slate-200 rounded-2xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all">
                                    </div>
                                </div>
                            </div>

                            <!-- Payment Method Selector -->
                            <div class="mt-6 space-y-3">
                                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Payment Method</label>

                                <!-- City type toggle -->
                                <div class="flex gap-2 mb-1">
                                    <button type="button" @click="deliveryCityType = 'pp'; if(paymentMethod==='cash') paymentMethod='cash'"
                                        class="flex-1 py-2 rounded-xl text-xs font-black border-2 transition-all"
                                        :class="deliveryCityType === 'pp' ? 'border-blue-500 bg-blue-50 text-blue-700' : 'border-slate-200 bg-white text-slate-500'">
                                        🏙️ Phnom Penh
                                    </button>
                                    <button type="button" @click="deliveryCityType = 'province'; paymentMethod = 'khqr'"
                                        class="flex-1 py-2 rounded-xl text-xs font-black border-2 transition-all"
                                        :class="deliveryCityType === 'province' ? 'border-blue-500 bg-blue-50 text-blue-700' : 'border-slate-200 bg-white text-slate-500'">
                                        🌾 Province
                                    </button>
                                </div>

                                <!-- KHQR option - always available -->
                                <button type="button" @click="paymentMethod = 'khqr'"
                                    class="w-full flex items-center gap-4 p-4 rounded-2xl border-2 transition-all"
                                    :class="paymentMethod === 'khqr' ? 'border-blue-500 bg-blue-50' : 'border-slate-200 bg-white hover:border-blue-300'">
                                    <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0" :class="paymentMethod === 'khqr' ? 'bg-blue-500' : 'bg-slate-100'">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" :stroke="paymentMethod === 'khqr' ? 'white' : '#64748b'" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3"/><path d="M17 21v-4"/><path d="M21 14v3"/><path d="M21 21h-4"/></svg>
                                    </div>
                                    <div class="text-left flex-1">
                                        <p class="font-black text-sm" :class="paymentMethod === 'khqr' ? 'text-blue-700' : 'text-slate-800'">Pay by KHQR</p>
                                        <p class="text-[11px] font-semibold text-slate-500">ABA · ACLEDA · Wing · TrueMoney and all Bakong banks</p>
                                    </div>
                                    <div class="w-5 h-5 rounded-full border-2 flex items-center justify-center flex-shrink-0" :class="paymentMethod === 'khqr' ? 'border-blue-500 bg-blue-500' : 'border-slate-300'">
                                        <div x-show="paymentMethod === 'khqr'" class="w-2 h-2 rounded-full bg-white"></div>
                                    </div>
                                </button>

                                <!-- Cash option - enabled only for Phnom Penh -->
                                <button type="button"
                                    @click="deliveryCityType === 'pp' && (paymentMethod = 'cash')"
                                    class="w-full flex items-center gap-4 p-4 rounded-2xl border-2 transition-all"
                                    :class="deliveryCityType !== 'pp'
                                        ? 'border-slate-100 bg-slate-50 opacity-50 cursor-not-allowed'
                                        : (paymentMethod === 'cash' ? 'border-green-500 bg-green-50' : 'border-slate-200 bg-white hover:border-green-300')">
                                    <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
                                        :class="deliveryCityType !== 'pp' ? 'bg-slate-100' : (paymentMethod === 'cash' ? 'bg-green-500' : 'bg-slate-100')">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                                            :stroke="deliveryCityType !== 'pp' ? '#cbd5e1' : (paymentMethod === 'cash' ? 'white' : '#64748b')"
                                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M6 12h.01M18 12h.01"/>
                                        </svg>
                                    </div>
                                    <div class="text-left flex-1">
                                        <div class="flex items-center gap-2">
                                            <p class="font-black text-sm"
                                                :class="deliveryCityType !== 'pp' ? 'text-slate-400' : (paymentMethod === 'cash' ? 'text-green-700' : 'text-slate-800')">
                                                Pay by Cash
                                            </p>
                                            <span x-show="deliveryCityType !== 'pp'" class="text-[9px] font-black bg-slate-200 text-slate-500 px-2 py-0.5 rounded-full uppercase tracking-wide">Phnom Penh only</span>
                                        </div>
                                        <p class="text-[11px] font-semibold"
                                            :class="deliveryCityType !== 'pp' ? 'text-slate-400' : 'text-slate-500'">
                                            Pay on delivery · Phnom Penh residents only
                                        </p>
                                    </div>
                                    <div class="w-5 h-5 rounded-full border-2 flex items-center justify-center flex-shrink-0"
                                        :class="deliveryCityType !== 'pp' ? 'border-slate-200' : (paymentMethod === 'cash' ? 'border-green-500 bg-green-500' : 'border-slate-300')">
                                        <div x-show="paymentMethod === 'cash' && deliveryCityType === 'pp'" class="w-2 h-2 rounded-full bg-white"></div>
                                    </div>
                                </button>
                            </div>

                            <div class="mt-8 flex gap-4">
                                <button @click="view = 'cart'" class="flex-1 bg-slate-100 text-slate-900 py-4 rounded-[1.5rem] font-black text-lg transition-all active:scale-[0.98]">Back to Cart</button>
                                <button @click="proceedToPayment()" 
                                        class="flex-1 py-4 rounded-[1.5rem] font-black text-lg transition-all active:scale-[0.98] shadow-xl"
                                        :disabled="!deliveryForm.name || !deliveryForm.phone || !deliveryForm.location"
                                        :class="(!deliveryForm.name || !deliveryForm.phone || !deliveryForm.location) ? 'bg-slate-300 text-slate-500 cursor-not-allowed' : (paymentMethod === 'cash' ? 'bg-green-600 hover:bg-green-700 text-white shadow-green-500/20' : 'bg-blue-600 hover:bg-blue-700 text-white shadow-blue-500/20')">
                                    <span x-text="paymentMethod === 'cash' ? 'Confirm Cash Order' : 'Proceed to KHQR'"></span>
                                </button>
                            </div>
                        </div>

                        <!-- Delivery Completed State -->
                        <div x-show="deliveryCompleted" class="bg-green-100/35 backdrop-blur-xl border border-green-200/60 rounded-[2rem] p-8 mb-10">
                            <div class="flex items-start gap-4 mb-6">
                                <div class="w-12 h-12 bg-green-500 text-white rounded-xl flex items-center justify-center flex-shrink-0">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                </div>
                                <div class="flex-1">
                                    <p class="font-black text-lg text-green-900 mb-2">Delivery Details Confirmed</p>
                                    <p class="text-sm text-green-800">Your delivery information has been saved</p>
                                </div>
                                <button @click="deliveryCompleted = false" class="text-green-600 hover:text-green-700 font-bold">Edit</button>
                            </div>
                            
                            <div class="space-y-3 bg-white/50 rounded-xl p-4">
                                <div class="flex justify-between">
                                    <span class="font-bold text-green-900">Name:</span>
                                    <span class="text-slate-600" x-text="deliveryForm.name"></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="font-bold text-green-900">Location:</span>
                                    <span class="text-slate-600" x-text="deliveryForm.location"></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="font-bold text-green-900">Phone:</span>
                                    <span class="text-slate-600">+855 <span x-text="deliveryForm.phone"></span></span>
                                </div>
                            </div>
                        </div>
                    </div>

                <!-- Order Summary Sidebar (Desktop) / Bottom (Mobile) -->
                <div class="lg:col-span-5 order-first lg:order-last">
                    <div class="bg-white/35 backdrop-blur-xl p-6 md:p-8 rounded-[2.5rem] border border-white/60 h-fit lg:sticky lg:top-28 shadow-sm">
                        <h3 class="font-black text-xl mb-6 flex justify-between">Order Summary <span class="text-blue-600" x-text="cartCount"></span></h3>
                        <div class="space-y-4 mb-8 max-h-[40vh] overflow-y-auto pr-2 no-scrollbar">
                            <template x-for="item in cart">
                                <div class="flex items-center gap-4">
                                    <div class="w-14 h-14 bg-slate-50 rounded-xl flex-shrink-0">
                                        <img :src="item.image" class="w-full h-full object-cover rounded-xl">
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="font-bold text-sm truncate" x-text="item.name"></p>
                                        <p class="text-[10px] font-bold text-slate-400" x-text="item.qty + ' units'"></p>
                                    </div>
                                    <span class="font-black text-sm" x-text="'$' + (item.price * item.qty).toFixed(2)"></span>
                                </div>
                            </template>
                        </div>
                        <div class="pt-6 border-t space-y-3">
                            <div class="flex justify-between text-xs font-bold text-slate-400 uppercase tracking-widest"><span>VAT (included)</span> <span>$0.00</span></div>
                            <div class="flex justify-between text-2xl font-black text-slate-900">
                                <span>Total</span>
                                <span class="text-blue-600" x-text="'$' + cartTotal.toFixed(2)"></span>
                            </div>
                        </div>
                    </div>

                    <div x-show="recommendedFromLastCategory().length" class="mt-6 bg-white/90 border border-slate-200 rounded-[2rem] p-5 shadow-lg">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.22em]">More from this category</p>
                                <p class="text-lg font-black text-slate-900" x-text="lastAddedCategory || (cart[cart.length - 1]?.category || 'Suggested for you')"></p>
                            </div>
                            <span class="text-[10px] font-black text-blue-600 uppercase tracking-[0.25em]">Picked for you</span>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <template x-for="rec in recommendedFromLastCategory()" :key="'rec-checkout-' + rec.id">
                                <div class="flex items-center gap-3 bg-white border border-slate-100 rounded-[1.25rem] p-3 shadow-sm">
                                    <div class="w-14 h-14 rounded-xl overflow-hidden flex-shrink-0 bg-slate-50">
                                        <img :src="rec.image" class="w-full h-full object-cover" loading="lazy">
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-[9px] font-extrabold text-blue-600 uppercase tracking-widest" x-text="rec.category"></p>
                                        <p class="text-sm font-black text-slate-900 truncate" x-text="rec.name"></p>
                                        <p class="text-xs font-bold text-slate-500">$<span x-text="rec.price.toFixed(2)"></span></p>
                                    </div>
                                    <button @click="addToCart(rec)"
                                            :disabled="stockLeft(rec) <= 0"
                                            class="h-10 min-w-[2.75rem] px-3 rounded-full font-black flex items-center justify-center shadow-sm transition-all"
                                            :class="stockLeft(rec) <= 0
                                                ? 'bg-slate-100 text-slate-400 border border-slate-200 cursor-not-allowed'
                                                : 'bg-white text-slate-900 border border-slate-200 hover:bg-blue-50 hover:border-blue-300 active:scale-95'"
                                            title="Add to cart">
                                        +
                                    </button>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cash Confirmation Modal -->
        <div x-show="showCashModal"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="fixed inset-0 bg-black/50 backdrop-blur-xl z-[100] flex items-center justify-center p-4">
            <div x-show="showCashModal"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="scale-95 opacity-0"
                 x-transition:enter-end="scale-100 opacity-100"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-start="scale-100 opacity-100"
                 x-transition:leave-end="scale-95 opacity-0"
                 class="bg-white rounded-[2.5rem] max-w-sm w-full shadow-2xl overflow-hidden">
                <div class="bg-gradient-to-r from-green-500 to-green-600 text-white p-6 text-center">
                    <div class="w-16 h-16 bg-white/20 rounded-full flex items-center justify-center mx-auto mb-3">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    </div>
                    <h2 class="text-2xl font-black">Order Confirmed!</h2>
                    <p class="text-green-100 font-semibold text-sm mt-1">Cash on Delivery</p>
                </div>
                <div class="p-8 text-center">
                    <p class="text-slate-700 font-bold mb-2">Your order has been placed successfully.</p>
                    <p class="text-slate-500 text-sm mb-1">Our staff will deliver your items and collect</p>
                    <p class="text-2xl font-black text-green-600 mb-1">$<span x-text="cashOrderTotal.toFixed(2)"></span></p>
                    <p class="text-slate-400 text-xs mb-6">cash upon delivery in Phnom Penh.</p>

                    <template x-if="invoiceLoading">
                        <p class="text-xs text-slate-400 mb-4 font-semibold animate-pulse">Generating invoice…</p>
                    </template>
                    <template x-if="invoiceNumber">
                        <div class="bg-slate-50 rounded-2xl p-4 mb-6 text-left">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Invoice</p>
                            <p class="font-black text-slate-900" x-text="invoiceNumber"></p>
                        </div>
                    </template>

                    <div class="flex flex-col gap-3">
                        <template x-if="invoiceNumber">
                            <a :href="'invoice.php?id=' + invoiceNumber" target="_blank"
                               class="w-full bg-green-600 text-white py-3 rounded-2xl font-black text-sm hover:bg-green-700 transition-colors text-center block">
                                View Invoice
                            </a>
                        </template>
                        <button @click="showCashModal = false; view = 'home'; invoiceNumber = ''"
                                class="w-full bg-slate-100 text-slate-700 py-3 rounded-2xl font-black text-sm hover:bg-slate-200 transition-colors">
                            Back to Store
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment Modal (Popup) -->
        <div x-show="showPaymentModal" 
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="fixed inset-0 bg-black/50 backdrop-blur-xl z-[100] flex items-center justify-center p-4">
            
            <!-- Modal Content -->
            <div x-show="showPaymentModal"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="scale-95 opacity-0"
                 x-transition:enter-end="scale-100 opacity-100"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-start="scale-100 opacity-100"
                 x-transition:leave-end="scale-95 opacity-0"
                 class="bg-white/30 backdrop-blur-2xl rounded-[2.5rem] max-w-md w-full shadow-2xl border border-white/50 overflow-hidden force-black-text max-h-[90vh] flex flex-col">
                
                <!-- Modal Header -->
                <div class="bg-gradient-to-r from-blue-600 to-slate-900 text-white p-6 flex-shrink-0">
                    <h2 class="text-2xl font-black">Payment</h2>
                </div>
                
                <!-- Modal Body -->
                <div class="p-8 text-center overflow-y-auto flex-1">
                    <!-- Loading State -->
                    <div x-show="paymentLoading" class="space-y-4">
                        <div class="flex justify-center">
                            <svg class="animate-spin h-12 w-12 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        </div>
                        <p class="text-slate-600 font-bold">Generating your KHQR code...</p>
                        <p class="text-sm text-slate-400">This may take a few seconds</p>
                    </div>
                    
                    <!-- QR Code Display -->
                    <div x-show="!paymentLoading && khqrImage" class="space-y-6">
                        <div class="inline-block">
                            <div class="w-64 h-64 rounded-[1.5rem] border-4 border-slate-900 p-3 bg-white flex items-center justify-center shadow-lg">
                                <img :src="khqrImage" alt="KHQR Payment Code" class="w-full h-full object-contain">
                            </div>
                        </div>
                        
                        <div>
                            <p class="text-sm text-slate-500 mb-2">Amount to Pay</p>
                            <p class="text-4xl font-black text-slate-900 mb-1"><span x-text="cartTotalKHR.toLocaleString()"></span> <span class="text-2xl">៛</span></p>
                            <p class="text-sm text-slate-400 mb-1">≈ $<span x-text="cartTotal.toFixed(2)"></span> USD</p>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.15em]" x-text="'Order #' + currentOrderId"></p>
                            <p class="text-xs font-bold text-amber-700 mt-3">Time left: <span x-text="paymentTimeLeftText"></span></p>
                        </div>
                        
                        <div class="bg-blue-100/40 backdrop-blur-md border border-blue-200/60 rounded-xl p-4 text-left">
                            <p class="text-sm font-bold text-blue-900 mb-2">📱 How to pay:</p>
                            <ol class="text-xs text-blue-800 space-y-1">
                                <li>1. Open your bank app (Bakong, ABA, etc.)</li>
                                <li>2. Scan the QR code above</li>
                                <li>3. Confirm and send payment</li>
                                <li>4. Payment confirms automatically</li>
                            </ol>
                        </div>
                        
                        <!-- Payment Status Bar -->
                        <div class="relative">
                            <div :class="{'bg-amber-100 border-amber-300': paymentStatusType === 'processing', 'bg-green-100 border-green-400': paymentStatusType === 'success'}" 
                                 class="border-2 rounded-xl p-4 transition-all duration-500">
                                <div class="flex items-center justify-center gap-3">
                                    <!-- Processing Spinner -->
                                    <template x-if="paymentStatusType === 'processing'">
                                        <svg class="animate-spin h-5 w-5 text-amber-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                    </template>
                                    <!-- Success Check -->
                                    <template x-if="paymentStatusType === 'success'">
                                        <svg class="h-5 w-5 text-green-600" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M20 6 9 17l-5-5"/>
                                        </svg>
                                    </template>
                                    <span :class="{'text-amber-800': paymentStatusType === 'processing', 'text-green-800': paymentStatusType === 'success'}" 
                                          class="font-bold text-sm" x-text="paymentStatusText"></span>
                                </div>
                                <!-- Progress Bar for Processing -->
                                <template x-if="paymentStatusType === 'processing'">
                                    <div class="mt-3 h-1.5 bg-amber-200 rounded-full overflow-hidden">
                                        <div class="h-full bg-amber-500 rounded-full animate-pulse" style="animation: progressPulse 2s ease-in-out infinite; width: 100%;"></div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Error State -->
                    <div x-show="paymentError" class="bg-red-50 border-2 border-red-200 rounded-xl p-4 text-left">
                        <p class="text-sm font-bold text-red-900 flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
                            Error
                        </p>
                        <p class="text-xs text-red-800 mt-2" x-text="paymentError"></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment Unsuccessful Modal -->
        <div x-show="showPaymentFailedModal"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="fixed inset-0 bg-black/50 backdrop-blur-xl z-[110] flex items-center justify-center p-4">
            <div x-show="showPaymentFailedModal"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="scale-95 opacity-0"
                 x-transition:enter-end="scale-100 opacity-100"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-start="scale-100 opacity-100"
                 x-transition:leave-end="scale-95 opacity-0"
                 class="bg-white/30 backdrop-blur-2xl rounded-[2.5rem] max-w-md w-full shadow-2xl border border-white/50 overflow-hidden force-black-text">
                <div class="bg-gradient-to-r from-blue-600 to-slate-900 text-white p-6">
                    <h2 class="text-2xl font-black">Payment Status</h2>
                </div>
                <div class="p-8 text-center">
                    <div class="w-20 h-20 bg-red-100 text-red-600 rounded-[1.5rem] flex items-center justify-center mx-auto mb-6">
                        <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>
                    </div>
                    <p class="text-2xl font-black text-slate-900 mb-3">Payment Unsuccessful</p>
                    <p class="text-sm text-slate-500 font-bold">Returning to website in 2 seconds...</p>
                </div>
            </div>
        </div>

        <!-- Invoice Success Popup Modal -->
        <div x-show="showInvoicePopup"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="fixed inset-0 bg-black/50 backdrop-blur-xl z-[110] flex items-center justify-center p-4">
            <div x-show="showInvoicePopup"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="scale-95 opacity-0"
                 x-transition:enter-end="scale-100 opacity-100"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-start="scale-100 opacity-100"
                 x-transition:leave-end="scale-95 opacity-0"
                 class="bg-white rounded-[2.5rem] max-w-md w-full shadow-2xl border border-slate-200 overflow-hidden">
                
                <!-- Header with Success Icon -->
                <div class="bg-gradient-to-r from-green-500 to-emerald-600 text-white p-8 text-center">
                    <div class="w-20 h-20 bg-white/20 backdrop-blur-sm rounded-[1.5rem] flex items-center justify-center mx-auto mb-4">
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    </div>
                    <h2 class="text-2xl font-black mb-2">Payment Successful!</h2>
                    <p class="text-green-100 text-sm font-medium">Your order has been confirmed</p>
                </div>
                
                <!-- Content -->
                <div class="p-8">
                    <!-- Order Info -->
                    <div class="bg-slate-50 rounded-2xl p-5 mb-6">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-sm text-slate-500 font-medium">Order ID</span>
                            <span class="text-sm font-black text-slate-900" x-text="completedOrderId"></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-slate-500 font-medium">Invoice</span>
                            <span x-show="invoiceLoading" class="text-sm text-slate-400">Generating...</span>
                            <span x-show="invoiceNumber && !invoiceLoading" class="text-sm font-black text-slate-900" x-text="invoiceNumber"></span>
                        </div>
                    </div>
                    
                    <!-- Invoice Actions -->
                    <p class="text-sm text-slate-600 font-medium text-center mb-4">View or download your invoice</p>
                    
                    <div class="grid grid-cols-3 gap-3 mb-6">
                        <!-- View Invoice -->
                        <a :href="'invoice.php?order=' + completedOrderId" 
                           target="_blank"
                           class="flex flex-col items-center gap-2 bg-blue-50 hover:bg-blue-100 text-blue-700 p-4 rounded-2xl transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <span class="text-xs font-bold">View</span>
                        </a>
                        
                        <!-- Download PDF -->
                        <a :href="'../backend/api/invoice.php?action=download_pdf&order_id=' + completedOrderId" 
                           class="flex flex-col items-center gap-2 bg-green-50 hover:bg-green-100 text-green-700 p-4 rounded-2xl transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                            <span class="text-xs font-bold">Download</span>
                        </a>
                        
                        <!-- Print -->
                        <a :href="'invoice.php?order=' + completedOrderId" 
                           target="_blank"
                           onclick="setTimeout(() => window.open(this.href).print(), 500); return false;"
                           class="flex flex-col items-center gap-2 bg-slate-50 hover:bg-slate-100 text-slate-700 p-4 rounded-2xl transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
                            <span class="text-xs font-bold">Print</span>
                        </a>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="flex flex-col gap-3">
                        <button @click="showInvoicePopup = false; view = 'home'; invoiceNumber = ''; paymentStatusText = 'Processing...'; paymentStatusType = 'processing'; window.location.href = 'index.php';" 
                                class="w-full bg-emerald-500 hover:bg-emerald-400 text-white py-4 rounded-2xl font-black transition-colors shadow-lg shadow-emerald-200">
                            Continue Shopping
                        </button>
                        <a href="https://t.me/monkey_Dluffy012" 
                           target="_blank"
                           class="w-full bg-blue-50 hover:bg-blue-100 text-blue-700 py-4 rounded-2xl font-bold text-center transition-colors flex items-center justify-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/><path d="m22 2-7 20-4-9-9-4Z"/></svg>
                            Track on Telegram
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Success View -->
        <div x-show="view === 'success'" class="max-w-2xl mx-auto px-6 py-20 md:py-32 text-center">
            <div class="w-24 h-24 md:w-32 md:h-32 bg-green-500 text-white rounded-[2.5rem] flex items-center justify-center mx-auto mb-10 shadow-2xl shadow-green-500/30 transform rotate-12">
                <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            </div>
            <h2 class="text-4xl md:text-5xl font-black mb-6 tracking-tighter">Awesome! Order Placed.</h2>
            <p class="text-slate-500 font-bold mb-8 leading-relaxed">Your order <span class="text-slate-900 font-black" x-text="'#' + (completedOrderId || 'BRY-XXXX')"></span> is being packed. Our driver will ping you on Telegram once they reach your Borey gate.</p>
            
            <!-- Invoice Section -->
            <div class="bg-white/50 backdrop-blur-xl border border-white/60 rounded-[2rem] p-6 mb-8 max-w-md mx-auto">
                <div class="flex items-center justify-center gap-3 mb-4">
                    <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    </div>
                    <div class="text-left">
                        <p class="font-black text-slate-900">Your Invoice</p>
                        <p x-show="invoiceNumber" class="text-xs text-slate-500" x-text="invoiceNumber"></p>
                        <p x-show="invoiceLoading" class="text-xs text-slate-400">Generating...</p>
                    </div>
                </div>
                
                <div class="flex gap-3 justify-center">
                    <a :href="'invoice.php?order=' + completedOrderId" 
                       target="_blank"
                       class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-5 py-3 rounded-xl font-bold flex items-center justify-center gap-2 transition-colors shadow-lg">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        View
                    </a>
                    <a :href="'../backend/api/invoice.php?action=download_pdf&order_id=' + completedOrderId" 
                       class="flex-1 bg-green-600 hover:bg-green-700 text-white px-5 py-3 rounded-xl font-bold flex items-center justify-center gap-2 transition-colors shadow-lg">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                        PDF
                    </a>
                    <a :href="'invoice.php?order=' + completedOrderId" 
                       target="_blank"
                       onclick="setTimeout(() => window.open(this.href).print(), 500); return false;"
                       class="bg-slate-200 hover:bg-slate-300 text-slate-700 px-5 py-3 rounded-xl font-bold flex items-center justify-center gap-2 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
                        Print
                    </a>
                </div>
            </div>
            
            <div class="flex flex-col sm:flex-row justify-center gap-4">
                <button @click="view = 'home'; cart = []; invoiceNumber = ''" class="bg-slate-900 text-white px-10 py-5 rounded-[1.5rem] font-black shadow-2xl shadow-slate-900/20 active:scale-95 transition-all">Shop More Essentials</button>
                <a href="https://t.me/monkey_Dluffy012" target="_blank" class="bg-white text-slate-900 border-2 border-slate-100 px-10 py-5 rounded-[1.5rem] font-black active:scale-95 transition-all inline-flex items-center justify-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/><path d="m22 2-7 20-4-9-9-4Z"/></svg>
                    Track on Telegram
                </a>
            </div>
        </div>

    </main>

    <!-- Global Cart Toast (Mobile Experience) -->
    <div x-show="showToast" 
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="translate-y-20 opacity-0"
         x-transition:enter-end="translate-y-0 opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="translate-y-0 opacity-100"
         x-transition:leave-end="translate-y-20 opacity-0"
         class="fixed bottom-6 left-1/2 -translate-x-1/2 z-[100] bg-slate-900 text-white px-6 py-4 rounded-2xl shadow-2xl flex items-center gap-4 min-w-[280px]">
        <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
        </div>
        <span class="font-bold text-sm">Added to your bag</span>
        <button @click="view = 'cart'; showToast = false" class="ml-auto text-blue-400 font-black text-xs uppercase tracking-widest">View Bag</button>
    </div>

    <!-- Responsive Footer -->
    <footer x-show="view === 'home'" class="bg-white border-t border-slate-100 py-8 md:py-20 px-4 mt-16 md:mt-20">
        <div class="max-w-7xl mx-auto grid grid-cols-3 gap-4 md:gap-12 text-center md:text-left items-start">
            <div class="space-y-4 md:space-y-6">
                <h2 class="text-xl md:text-2xl font-black tracking-tighter uppercase italic">Borey<span class="text-blue-600">.store</span></h2>
                <p class="text-slate-400 text-xs md:text-sm leading-relaxed max-w-xs mx-auto md:mx-0 font-medium">Borey Store with all your staffs.</p>
                <div class="flex justify-center md:justify-start gap-3 md:gap-4">
                    <a href="https://www.facebook.com/pich.pich.29" target="_blank" rel="noopener noreferrer" aria-label="Facebook page" class="w-9 h-9 md:w-10 md:h-10 bg-slate-100 rounded-full flex items-center justify-center text-slate-600 hover:text-blue-600 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>
                        </svg>
                    </a>
                    <a href="https://t.me/monkey_Dluffy012" target="_blank" rel="noopener noreferrer" aria-label="Telegram support" class="w-9 h-9 md:w-10 md:h-10 bg-slate-100 rounded-full flex items-center justify-center text-slate-600 hover:text-blue-600 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    </a>
                </div>
            </div>
            <div class="space-y-4 md:space-y-6 pt-1 md:pt-0">
                <h4 class="text-[10px] md:text-xs font-black text-slate-900 uppercase tracking-[0.2em]">Quick Links</h4>
                <div class="flex flex-col gap-3 md:gap-4 text-slate-500 font-bold text-xs md:text-sm">
                    <a href="https://t.me/monkey_Dluffy012" target="_blank" rel="noopener noreferrer" class="hover:text-blue-600 transition-colors">Track Order</a>
                    <a href="https://maps.app.goo.gl/LnoejzUzB3F1M1119" target="_blank" rel="noopener noreferrer" class="hover:text-blue-600 transition-colors">Borey Locations</a>
                    <a href="#" class="hover:text-blue-600 transition-colors flex items-center justify-center md:justify-start gap-2">Merchant Program
                        <span class="text-[10px] md:text-xs text-slate-400">(KHQR/ABA)</span>
                    </a>
                </div>
            </div>
            <div class="space-y-4 md:space-y-6 pt-1 md:pt-0">
                <h4 class="text-[10px] md:text-xs font-black text-slate-900 uppercase tracking-[0.2em]">Support</h4>
                <div class="flex flex-col gap-3 md:gap-4 text-slate-500 font-bold text-xs md:text-sm">
                    <a href="https://t.me/monkey_Dluffy012" target="_blank" rel="noopener noreferrer" class="hover:text-blue-600 transition-colors">Telegram Support</a>
                </div>
            </div>
        </div>
        <div class="max-w-7xl mx-auto border-t border-slate-50 mt-10 md:mt-16 pt-6 md:pt-8 text-center">
            <p class="text-slate-300 text-[9px] md:text-[10px] font-bold uppercase tracking-widest">© 2026 BOREY STORE CO. LTD • PHNOM PENH, CAMBODIA</p>
        </div>
    </footer>
  
</body>
</html>





