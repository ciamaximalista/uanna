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
                const previous = preview.querySelector('img, span');
                if (previous) {
                    previous.remove();
                }
                draw();
            };
            img.src = url;
        });

        zoom.addEventListener('input', draw);
        panX.addEventListener('input', draw);
        panY.addEventListener('input', draw);
    }

    function initInfiniteTimeline(root) {
        if (root.dataset.timelineInitialized === '1') {
            return;
        }

        root.dataset.timelineInitialized = '1';
        const button = root.querySelector('button');
        let loading = false;

        function loadMore() {
            const url = root.dataset.nextUrl || '';
            if (loading || url === '') {
                return;
            }

            loading = true;
            root.classList.add('is-loading');
            if (button) {
                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
            }

            fetch(url, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'fetch',
                },
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('timeline');
                    }

                    return response.json();
                })
                .then(function (payload) {
                    const template = document.createElement('template');
                    template.innerHTML = payload.html || '';
                    root.before(template.content);

                    if (payload.next) {
                        root.dataset.nextUrl = payload.next;
                        loading = false;
                        root.classList.remove('is-loading');
                        if (button) {
                            button.disabled = false;
                            button.removeAttribute('aria-busy');
                        }
                    } else {
                        root.remove();
                    }
                })
                .catch(function () {
                    loading = false;
                    root.classList.remove('is-loading');
                    root.classList.add('has-error');
                    if (button) {
                        button.disabled = false;
                        button.removeAttribute('aria-busy');
                    }
                });
        }

        if (button) {
            button.addEventListener('click', loadMore);
        }

        root.oannesLoadMore = loadMore;

        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        loadMore();
                    }
                });
            }, { rootMargin: '600px 0px' });
            observer.observe(root);
        }
    }

    function initPersistentPostImages(input) {
        if (input.dataset.persistentImagesInitialized === '1') {
            return;
        }

        input.dataset.persistentImagesInitialized = '1';
        let files = [];
        let refreshTimer = 0;

        input.classList.add('post-image-input');

        const status = document.createElement('p');
        status.className = 'file-selection-status muted';
        status.setAttribute('aria-live', 'polite');
        input.insertAdjacentElement('afterend', status);

        function updateStatus() {
            if (input.files && input.files.length > 0) {
                files = Array.from(input.files).slice(0, 4);
            }

            if (files.length === 0) {
                status.textContent = '';
                input.classList.remove('has-files');
                return;
            }

            input.classList.add('has-files');
            status.textContent = 'Archivos seleccionados: ' + files.map(function (file) {
                return file.name;
            }).join(', ');
            revealNextImageSlot(input);
        }

        input.oannesUpdateSelectedFiles = updateStatus;

        function scheduleStatusUpdate() {
            window.clearTimeout(refreshTimer);
            updateStatus();
            refreshTimer = window.setTimeout(updateStatus, 350);
        }

        function restoreInputFiles() {
            if (files.length === 0 || typeof DataTransfer === 'undefined') {
                return;
            }

            const transfer = new DataTransfer();
            files.forEach(function (file) {
                transfer.items.add(file);
            });
            input.files = transfer.files;
        }

        input.addEventListener('change', function () {
            if (input.files && input.files.length > 0) {
                files = Array.from(input.files).slice(0, 4);
                restoreInputFiles();
            }

            scheduleStatusUpdate();
        });
        input.addEventListener('input', scheduleStatusUpdate);
        input.addEventListener('blur', scheduleStatusUpdate);
        input.addEventListener('focus', function () {
            window.oannesActivePostImageInput = input;
        });
        input.addEventListener('click', function () {
            window.oannesActivePostImageInput = input;
        });
    }

    function revealNextImageSlot(input) {
        const slot = input.closest('.post-image-slot');
        const group = input.closest('.post-image-inputs');
        if (!slot || !group) {
            return;
        }

        const slots = Array.from(group.querySelectorAll('.post-image-slot'));
        const index = slots.indexOf(slot);
        const next = index >= 0 ? slots[index + 1] : null;
        if (next) {
            next.classList.add('is-visible');
        }
    }

    function isPostImageInput(input) {
        return input
            && input.tagName === 'INPUT'
            && input.type === 'file'
            && input.name === 'image_upload[]';
    }

    function updatePostImageInput(input) {
        if (!isPostImageInput(input)) {
            return;
        }

        initPersistentPostImages(input);
        if (typeof input.oannesUpdateSelectedFiles === 'function') {
            input.oannesUpdateSelectedFiles();
        }
    }

    function updateActivePostImageInput() {
        const input = window.oannesActivePostImageInput;
        if (isPostImageInput(input)) {
            updatePostImageInput(input);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.image-cropper').forEach(initCropper);
        document.querySelectorAll('input[type="file"]').forEach(updatePostImageInput);
        document.querySelectorAll('.timeline-more').forEach(initInfiniteTimeline);
        document.addEventListener('change', function (event) {
            updatePostImageInput(event.target);
        }, true);
        window.addEventListener('focus', updateActivePostImageInput);
        window.addEventListener('pageshow', updateActivePostImageInput);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                updateActivePostImageInput();
            }
        });
        document.addEventListener('click', function (event) {
            const button = event.target.closest('.timeline-more button');
            if (!button) {
                return;
            }

            event.preventDefault();
            const root = button.closest('.timeline-more');
            if (root) {
                initInfiniteTimeline(root);
                if (typeof root.oannesLoadMore === 'function') {
                    root.oannesLoadMore();
                }
            }
        });
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
