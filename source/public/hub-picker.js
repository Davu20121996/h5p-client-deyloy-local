const pickerFrame = document.querySelector('[data-hub-picker-frame]');
const pickerForm = document.querySelector('[data-hub-picker-form]');

if (pickerFrame && pickerForm) {
    pickerFrame.hidden = true;
    pickerForm.addEventListener('submit', (event) => {
        if (event.submitter?.value !== '0') {
            pickerFrame.hidden = false;
            pickerFrame.scrollIntoView({ block: 'nearest' });
        }
    });
    window.addEventListener('message', (event) => {
        if (event.origin === window.location.origin
            && event.source === pickerFrame.contentWindow
            && event.data === 'h5p-selection-complete') {
            window.location.reload();
        }
    });
}

if (document.querySelector('[data-hub-picker-result]') && window.parent !== window) {
    window.parent.postMessage('h5p-selection-complete', window.location.origin);
}
