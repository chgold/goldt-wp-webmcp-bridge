function copyCode() {
    const code = document.getElementById('authCode').textContent;
    navigator.clipboard.writeText(code).then(() => {
        const btn = document.querySelector('.copy-btn');
        const success = document.getElementById('copySuccess');
        btn.style.display = 'none';
        success.style.display = 'block';
        setTimeout(() => {
            btn.style.display = 'block';
            success.style.display = 'none';
        }, 3000);
    });
}
