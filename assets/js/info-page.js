function aicCopy(inputId, btn) {
	var el = document.getElementById(inputId);
	if (!el) return;
	var text = el.tagName === 'TEXTAREA' ? el.value : el.value;
	if (navigator.clipboard && navigator.clipboard.writeText) {
		navigator.clipboard.writeText(text).then(function() {
			aicFlashCopied(btn);
		}).catch(function() {
			aicFallbackCopy(el, btn);
		});
	} else {
		aicFallbackCopy(el, btn);
	}
}

function aicFallbackCopy(el, btn) {
	el.select();
	el.setSelectionRange(0, 99999);
	try {
		document.execCommand('copy');
		aicFlashCopied(btn);
	} catch (e) {}
}

function aicFlashCopied(btn) {
	var orig = btn.textContent;
	btn.textContent = 'Copied!';
	btn.classList.add('copied');
	setTimeout(function() {
		btn.textContent = orig;
		btn.classList.remove('copied');
	}, 2000);
}
