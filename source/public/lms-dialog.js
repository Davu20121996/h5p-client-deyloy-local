document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-modal-open]');
    if (opener) {
        document.getElementById(opener.dataset.modalOpen)?.showModal();
    }
    const closer = event.target.closest('[data-modal-close]');
    if (closer) {
        closer.closest('dialog').close();
    }
});

document.querySelector('dialog[data-modal-reopen]')?.showModal();
