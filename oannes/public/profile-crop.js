(function () {
    function initCropper(root) {
        const input = root.querySelector('input[type="file"]');
        const hidden = root.querySelector('input[type="hidden"]');
        const canvas = root.querySelector('canvas');
        const preview = root.querySelector('.crop-preview');
        const zoom = root.querySelector('.crop-zoom');
        const panX = root.querySelector('.crop-x');
        const panY = root.querySelector('.crop-y');
        const aspect = Number(root.dataset.aspect || '1');
        const img = new Image();
        let loaded = false;

        if (!input || !hidden || !canvas || !preview || !zoom || !panX || !panY) {
            return;
        }

        canvas.width = aspect === 1 ? 512 : 1500;
        canvas.height = aspect === 1 ? 512 : 500;

        function draw() {
            if (!loaded) {
                return;
            }

            const ctx = canvas.getContext('2d');
            const scale = Number(zoom.value || '1');
            const base = Math.max(canvas.width / img.width, canvas.height / img.height);
            const drawW = img.width * base * scale;
            const drawH = img.height * base * scale;
            const maxX = Math.max(0, (drawW - canvas.width) / 2);
            const maxY = Math.max(0, (drawH - canvas.height) / 2);
            const x = (canvas.width - drawW) / 2 + Number(panX.value || '0') * maxX;
            const y = (canvas.height - drawH) / 2 + Number(panY.value || '0') * maxY;

            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, x, y, drawW, drawH);
            hidden.value = canvas.toDataURL('image/jpeg', 0.9);
        }

        input.addEventListener('change', function () {
            const file = input.files && input.files[0];
            if (!file) {
                return;
            }

            const url = URL.createObjectURL(file);
            img.onload = function () {
                URL.revokeObjectURL(url);
                loaded = true;
                canvas.hidden = false;
                preview.querySelector('img, span')?.remove();
                draw();
            };
            img.src = url;
        });

        zoom.addEventListener('input', draw);
        panX.addEventListener('input', draw);
        panY.addEventListener('input', draw);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.image-cropper').forEach(initCropper);
        document.querySelectorAll('form[enctype="multipart/form-data"]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (form.dataset.submitting === '1') {
                    event.preventDefault();
                    return;
                }

                form.dataset.submitting = '1';
                form.querySelectorAll('button[type="submit"]').forEach(function (button) {
                    button.disabled = true;
                    button.setAttribute('aria-busy', 'true');
                });
            });
        });
    });
})();
