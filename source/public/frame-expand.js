for (const frame of document.querySelectorAll('[data-expandable-frame]')) {
    const button = frame.querySelector('[data-frame-expand]');
    const label = button.querySelector('[data-expand-label]');

    function setExpanded(expanded) {
        frame.classList.toggle('is-expanded', expanded);
        document.body.classList.toggle('lesson-expanded', expanded);
        button.setAttribute('aria-pressed', String(expanded));
        label.textContent = expanded ? 'Thu gọn' : 'Phóng to';
    }

    button.addEventListener('click', () => setExpanded(!frame.classList.contains('is-expanded')));
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && frame.classList.contains('is-expanded')) {
            setExpanded(false);
            button.focus();
        }
    });
}
