function parseReceiptDecimal(value) {
    if (!/^\d{1,14}([.,]\d{1,6})?$/.test(value)) throw new Error('Kiểm tra số lượng và tiền, không dùng dấu phân cách hàng nghìn.');
    const [whole,part='']=value.replace(',','.').split('.');return BigInt(whole)*1000000n+BigInt(part.padEnd(6,'0'));
}
function formatReceiptDecimal(value) {
    const whole=(value/1000000n).toString().replace(/\B(?=(\d{3})+(?!\d))/g,'.'),part=(value%1000000n).toString().padStart(6,'0').replace(/0+$/,'');
    return whole+(part?','+part:'');
}
