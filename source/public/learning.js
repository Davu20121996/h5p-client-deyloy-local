const learningPanel = document.querySelector('[data-learning-status]');
if (learningPanel) {
    const message = learningPanel.querySelector('[data-learning-message]');
    const score = learningPanel.querySelector('[data-learning-score]');
    const player = learningPanel.querySelector('[data-learning-player]');
    const label = learningPanel.querySelector('[data-learning-label]');
    let timer;
    let active = true;
    let launched = false;
    async function refresh() {
        if (!active) return;
        try {
            const response = await fetch(learningPanel.dataset.learningStatus, {
                headers: { Accept: 'application/json' }, cache: 'no-store',
            });
            if ([401, 403, 404].includes(response.status) || response.redirected) {
                active = false;
                message.textContent = 'Phiên học hoặc quyền truy cập đã thay đổi. Vui lòng tải lại trang.';
                return;
            }
            if (!response.ok) throw new Error('Không thể cập nhật kết quả');
            const result = await response.json();
            score.replaceChildren(document.createTextNode(result.score === null ? '— ' : `${result.score} `));
            const maximum = document.createElement('small');
            maximum.textContent = `/ ${result.maximum}`;
            score.append(maximum);
            learningPanel.dataset.state = result.completed ? 'completed' : (launched ? 'learning' : 'ready');
            label.textContent = result.completed ? 'Đã hoàn thành' : (launched ? 'Đang học' : 'Sẵn sàng bắt đầu');
            message.textContent = result.completed
                ? 'Bạn đã hoàn thành bài học. Kết quả đã được hệ thống ghi nhận.'
                : (launched ? 'Hệ thống đang chờ H5P gửi kết quả hoàn thành.' : 'Chưa có kết quả hoàn thành. Bạn có thể mở bài để học.');
        } catch {
            message.textContent = 'Chưa cập nhật được kết quả. Hệ thống sẽ tự thử lại.';
        } finally {
            if (active) timer = setTimeout(refresh, 5000);
        }
    }
    learningPanel.querySelector('[data-learning-launch]').addEventListener('submit', () => {
        clearTimeout(timer);
        active = true;
        launched = true;
        learningPanel.querySelector('[data-learning-placeholder]').hidden = true;
        player.querySelector('iframe').hidden = false;
        learningPanel.dataset.state = 'learning';
        label.textContent = 'Đang học';
        message.textContent = 'Đang mở bài học. Điểm sẽ tự cập nhật khi có kết quả.';
        timer = setTimeout(refresh, 5000);
    });
    timer = setTimeout(refresh, 0);
    window.addEventListener('pageshow', () => {
        if (!active) {
            active = true;
            clearTimeout(timer);
            timer = setTimeout(refresh, 0);
        }
    });
    window.addEventListener('pagehide', () => { active = false; clearTimeout(timer); });
}
