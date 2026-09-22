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

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            textarea.style.top = '0';
            document.body.appendChild(textarea);
            textarea.select();

            try {
                if (document.execCommand('copy')) {
                    resolve();
                } else {
                    reject(new Error('copy'));
                }
            } catch (error) {
                reject(error);
            } finally {
                textarea.remove();
            }
        });
    }

    function handleCopyPostUrl(event) {
        const button = event.target.closest('.copy-post-url');
        if (!button) {
            return false;
        }

        event.preventDefault();
        const rawUrl = button.dataset.copyUrl || '';
        if (rawUrl === '') {
            return true;
        }

        const url = new URL(rawUrl, window.location.href).href;
        copyText(url)
            .then(function () {
                button.classList.add('is-copied');
                window.setTimeout(function () {
                    button.classList.remove('is-copied');
                }, 1200);
            })
            .catch(function () {
                button.classList.add('has-error');
                window.setTimeout(function () {
                    button.classList.remove('has-error');
                }, 1200);
            });

        return true;
    }

    // Mention autocomplete: typing "@" in a message textarea offers the
    // accounts the user follows (local ones by username, remote ones by full
    // handle) and narrows the list as the user keeps typing.
    const mentionState = {
        textarea: null,
        list: null,
        items: [],
        selected: 0,
        start: 0,
        query: '',
    };
    let mentionSuggestionsPromise = null;
    const MENTION_LIMIT = 8;

    function isMentionTextarea(element) {
        return element instanceof HTMLTextAreaElement && element.name === 'content';
    }

    function loadMentionSuggestions() {
        if (mentionSuggestionsPromise) {
            return mentionSuggestionsPromise;
        }

        const url = document.body.dataset.mentionsUrl || '';
        if (url === '') {
            return Promise.resolve([]);
        }

        mentionSuggestionsPromise = fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) {
                return response.ok ? response.json() : { suggestions: [] };
            })
            .then(function (payload) {
                return Array.isArray(payload.suggestions) ? payload.suggestions : [];
            })
            .catch(function () {
                mentionSuggestionsPromise = null;
                return [];
            });

        return mentionSuggestionsPromise;
    }

    function mentionQueryAt(textarea) {
        const caret = textarea.selectionStart;
        if (caret === null || caret !== textarea.selectionEnd) {
            return null;
        }

        const before = textarea.value.slice(0, caret);
        const match = /(^|[^\w@])@([A-Za-z0-9_.-]*(?:@[A-Za-z0-9.-]*)?)$/.exec(before);
        if (!match) {
            return null;
        }

        return {
            start: caret - match[2].length - 1,
            query: match[2],
        };
    }

    function filterMentionSuggestions(suggestions, query) {
        const needle = query.toLowerCase();
        const matches = suggestions.filter(function (item) {
            const handle = String(item.handle || '').slice(1).toLowerCase();
            const name = String(item.name || '').toLowerCase();
            if (needle === '') {
                return true;
            }

            if (needle.indexOf('@') !== -1) {
                return handle.indexOf(needle) === 0;
            }

            return handle.indexOf(needle) === 0 || name.indexOf(needle) !== -1;
        });

        return matches.slice(0, MENTION_LIMIT);
    }

    function closeMentionList() {
        if (mentionState.list) {
            mentionState.list.remove();
        }

        mentionState.textarea = null;
        mentionState.list = null;
        mentionState.items = [];
        mentionState.selected = 0;
    }

    function positionMentionList(textarea, list) {
        const parent = textarea.parentElement;
        if (!parent) {
            return;
        }

        if (getComputedStyle(parent).position === 'static') {
            parent.style.position = 'relative';
        }

        list.style.top = (textarea.offsetTop + textarea.offsetHeight) + 'px';
        list.style.left = textarea.offsetLeft + 'px';
        list.style.width = textarea.offsetWidth + 'px';
    }

    function renderMentionList(textarea, items) {
        if (items.length === 0) {
            closeMentionList();
            return;
        }

        if (mentionState.textarea !== textarea || !mentionState.list) {
            closeMentionList();
            const list = document.createElement('ul');
            list.className = 'mention-suggestions';
            list.setAttribute('role', 'listbox');
            textarea.insertAdjacentElement('afterend', list);
            mentionState.textarea = textarea;
            mentionState.list = list;
        }

        mentionState.items = items;
        mentionState.selected = Math.min(mentionState.selected, items.length - 1);
        const list = mentionState.list;
        list.innerHTML = '';

        items.forEach(function (item, index) {
            const li = document.createElement('li');
            li.setAttribute('role', 'option');
            li.dataset.index = String(index);
            if (index === mentionState.selected) {
                li.classList.add('is-selected');
                li.setAttribute('aria-selected', 'true');
            }

            const avatar = String(item.avatar || '');
            if (avatar !== '') {
                const img = document.createElement('img');
                img.src = avatar;
                img.alt = '';
                img.loading = 'lazy';
                li.appendChild(img);
            } else {
                const fallback = document.createElement('span');
                fallback.className = 'mention-avatar-fallback';
                fallback.textContent = String(item.handle || '?').slice(1, 2).toUpperCase();
                li.appendChild(fallback);
            }

            const text = document.createElement('span');
            text.className = 'mention-text';
            const name = document.createElement('strong');
            name.textContent = String(item.name || item.handle || '');
            const handle = document.createElement('small');
            handle.textContent = String(item.handle || '');
            text.appendChild(name);
            text.appendChild(handle);
            li.appendChild(text);
            list.appendChild(li);
        });

        positionMentionList(textarea, list);
    }

    function updateMentionList(textarea) {
        const token = mentionQueryAt(textarea);
        if (!token) {
            closeMentionList();
            return;
        }

        mentionState.start = token.start;
        mentionState.query = token.query;

        loadMentionSuggestions().then(function (suggestions) {
            const current = mentionQueryAt(textarea);
            if (!current || document.activeElement !== textarea || current.start !== token.start) {
                return;
            }

            if (mentionState.textarea !== textarea || mentionState.query !== current.query) {
                mentionState.selected = 0;
            }

            mentionState.query = current.query;
            renderMentionList(textarea, filterMentionSuggestions(suggestions, current.query));
        });
    }

    function insertMention(index) {
        const textarea = mentionState.textarea;
        const item = mentionState.items[index];
        if (!textarea || !item) {
            return;
        }

        const caret = textarea.selectionStart;
        const before = textarea.value.slice(0, mentionState.start);
        const after = textarea.value.slice(caret);
        const inserted = String(item.handle || '') + (after.charAt(0) === ' ' ? '' : ' ');
        textarea.value = before + inserted + after;
        const position = before.length + inserted.length;
        textarea.setSelectionRange(position, position);
        closeMentionList();
        textarea.focus();
        textarea.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function moveMentionSelection(delta) {
        const count = mentionState.items.length;
        if (count === 0 || !mentionState.list) {
            return;
        }

        mentionState.selected = (mentionState.selected + delta + count) % count;
        mentionState.list.querySelectorAll('li').forEach(function (li, index) {
            const selected = index === mentionState.selected;
            li.classList.toggle('is-selected', selected);
            if (selected) {
                li.setAttribute('aria-selected', 'true');
                li.scrollIntoView({ block: 'nearest' });
            } else {
                li.removeAttribute('aria-selected');
            }
        });
    }

    function handleMentionKeydown(event) {
        if (!isMentionTextarea(event.target) || mentionState.textarea !== event.target || !mentionState.list) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            moveMentionSelection(1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            moveMentionSelection(-1);
        } else if (event.key === 'Enter' || event.key === 'Tab') {
            event.preventDefault();
            insertMention(mentionState.selected);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            closeMentionList();
        }
    }

    function initMentionAutocomplete() {
        document.addEventListener('input', function (event) {
            if (isMentionTextarea(event.target)) {
                updateMentionList(event.target);
            }
        });
        document.addEventListener('keydown', handleMentionKeydown);
        document.addEventListener('keyup', function (event) {
            const caretKeys = ['ArrowLeft', 'ArrowRight', 'Home', 'End'];
            if (isMentionTextarea(event.target) && mentionState.textarea === event.target && caretKeys.indexOf(event.key) !== -1) {
                updateMentionList(event.target);
            }
        });
        document.addEventListener('click', function (event) {
            if (isMentionTextarea(event.target) && mentionState.textarea === event.target) {
                updateMentionList(event.target);
            }
        });
        document.addEventListener('mousedown', function (event) {
            const option = event.target.closest('.mention-suggestions li');
            if (option) {
                event.preventDefault();
                insertMention(Number(option.dataset.index || '0'));
                return;
            }

            if (mentionState.list && !mentionState.list.contains(event.target) && event.target !== mentionState.textarea) {
                closeMentionList();
            }
        });
        document.addEventListener('focusout', function (event) {
            if (event.target === mentionState.textarea) {
                window.setTimeout(function () {
                    if (document.activeElement !== mentionState.textarea) {
                        closeMentionList();
                    }
                }, 150);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.image-cropper').forEach(initCropper);
        document.querySelectorAll('input[type="file"]').forEach(updatePostImageInput);
        document.querySelectorAll('.timeline-more').forEach(initInfiniteTimeline);
        initMentionAutocomplete();
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
            if (handleCopyPostUrl(event)) {
                return;
            }

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
