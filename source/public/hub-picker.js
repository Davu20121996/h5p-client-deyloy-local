const pickerFrame = document.querySelector('[data-hub-picker-frame]');
const pickerForm = document.querySelector('[data-hub-picker-form]');
const pickerPanel = document.querySelector('[data-hub-picker-panel]');

if (pickerFrame && pickerForm && pickerPanel) {
    pickerForm.addEventListener('submit', () => {
        pickerPanel.hidden = false;
        pickerPanel.scrollIntoView({ block: 'start' });
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
