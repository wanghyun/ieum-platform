(function () {
    let pendingForm = null;
    let pendingSubmitter = null;

    const TEXT = {
        title: '\uBC1C\uC1A1 \uD655\uC778',
        message: '\uC120\uD0DD\uD55C \uB300\uC0C1\uC5D0\uAC8C \uBC1C\uC1A1 \uCC98\uB9AC\uD569\uB2C8\uB2E4.',
        countTitle: '\uBC1C\uC1A1 \uB300\uC0C1',
        help: '\uBC1C\uC1A1\uC744 \uB204\uB974\uBA74 \uBB38\uC790 \uD050 \uB610\uB294 \uCCAD\uAD6C\uC11C \uBC1C\uC1A1 \uCC98\uB9AC\uB85C \uB118\uC5B4\uAC11\uB2C8\uB2E4. \uC81C\uC678 \uC870\uAC74\uC740 \uC11C\uBC84\uC5D0\uC11C \uD55C \uBC88 \uB354 \uD655\uC778\uD569\uB2C8\uB2E4.',
        cancel: '\uCDE8\uC18C',
        send: '\uBC1C\uC1A1',
        empty: '\uBC1C\uC1A1\uD560 \uB300\uC0C1\uC744 \uBA3C\uC800 \uC120\uD0DD\uD574 \uC8FC\uC138\uC694.',
        itemUnit: '\uAC74',
        studentUnit: '\uBA85'
    };

    function selectedCount(form, submitter) {
        if (form.dataset.sendCountLabel) {
            return {text: form.dataset.sendCountLabel, ok: true};
        }
        if (submitter && submitter.dataset && submitter.dataset.sendCountLabel) {
            return {text: submitter.dataset.sendCountLabel, ok: true};
        }
        if (form.dataset.sendCount) {
            const fixed = Number(form.dataset.sendCount) || 0;
            return {text: fixed + (form.dataset.sendUnit || TEXT.itemUnit), ok: fixed > 0};
        }

        const selector = form.dataset.sendSelector || 'input[name="student_ids[]"]:checked:not(:disabled), input[name="payment_ids[]"]:checked:not(:disabled)';
        const documentCount = document.querySelectorAll(selector).length;
        const formCount = form.querySelectorAll(selector).length;
        const count = documentCount || formCount;
        return {text: count + (form.dataset.sendUnit || TEXT.studentUnit), ok: count > 0};
    }

    function summaryItems(form, submitter) {
        const raw = (submitter && submitter.dataset.sendSummary) || form.dataset.sendSummary || '';
        if (!raw) return [];
        return raw.split('|')
            .map((item) => item.trim())
            .filter(Boolean)
            .slice(0, 12)
            .map((item) => {
                const match = item.match(/^(ok|warn|danger|info|muted):(.*)$/);
                if (!match) return {type: 'info', text: item};
                return {type: match[1], text: match[2].trim()};
            });
    }

    function parseMessageValues(form) {
        if (!form || !form.dataset.messageValues) return {};
        try {
            return JSON.parse(form.dataset.messageValues);
        } catch (error) {
            return {};
        }
    }

    function renderMessagePreview(template, values) {
        const source = template || '';
        return source.replace(/\{([^}]+)\}/g, (match, key) => {
            if (!Object.prototype.hasOwnProperty.call(values, key)) return match;
            const value = values[key];
            return value === null || value === undefined ? '' : String(value);
        });
    }

    function humanizeTemplate(template) {
        return template || '';
        return (template || '')
            .replace(/\{academy_name\}/g, '{도장명}')
            .replace(/\{student_name\}/g, '{학생명}')
            .replace(/\{billing_month\}/g, '{청구월}')
            .replace(/\{balance\}/g, '{금액}')
            .replace(/\{due_date\}/g, '{납부일}')
            .replace(/\{amount_due\}/g, '{청구액}')
            .replace(/\{amount_paid\}/g, '{입금액}');
    }

    function ensureSendConfirm() {
        let backdrop = document.getElementById('sendConfirmBackdrop');
        let modal = document.getElementById('sendConfirmModal');
        if (backdrop && modal) return {backdrop, modal};

        backdrop = document.createElement('div');
        backdrop.className = 'send-confirm-backdrop';
        backdrop.id = 'sendConfirmBackdrop';

        modal = document.createElement('section');
        modal.className = 'send-confirm-modal';
        modal.id = 'sendConfirmModal';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.innerHTML = [
            '<h2 id="sendConfirmTitle"></h2>',
            '<p id="sendConfirmMessage"></p>',
            '<div class="send-confirm-count"><span id="sendConfirmCountLabel"></span><strong id="sendConfirmCount"></strong></div>',
            '<ul class="send-confirm-summary" id="sendConfirmSummary"></ul>',
            '<div class="send-confirm-editor" id="sendConfirmEditor" hidden>',
            '<div class="send-confirm-editor-head">',
            '<label for="sendConfirmTemplate">\uC774\uBC88 \uBC1C\uC1A1 \uBB38\uAD6C</label>',
            '<span>\uC218\uC815\uD558\uBA74 \uC544\uB798 \uBBF8\uB9AC\uBCF4\uAE30\uAC00 \uBC14\uB85C \uBC14\uB01D\uB2C8\uB2E4.</span>',
            '</div>',
            '<textarea id="sendConfirmTemplate" rows="7"></textarea>',
            '<label class="send-confirm-save"><input type="checkbox" id="sendConfirmSaveTemplate" value="1"> \uC218\uC815\uD55C \uBB38\uAD6C\uB97C \uAE30\uBCF8 \uBB38\uAD6C\uB85C \uC800\uC7A5</label>',
            '<p class="send-confirm-tokens" id="sendConfirmTokens"></p>',
            '<div class="send-confirm-preview" id="sendConfirmPreviewWrap" hidden>',
            '<strong>\uBCF4\uD638\uC790\uC5D0\uAC8C \uBCF4\uC774\uB294 \uB0B4\uC6A9</strong>',
            '<div id="sendConfirmPreview"></div>',
            '</div>',
            '</div>',
            '<p id="sendConfirmHelp"></p>',
            '<div class="send-confirm-actions">',
            '<button type="button" class="btn" id="sendConfirmCancel"></button>',
            '<button type="button" class="btn primary" id="sendConfirmSubmit"></button>',
            '</div>'
        ].join('');

        document.body.append(backdrop, modal);

        const close = () => {
            pendingForm = null;
            pendingSubmitter = null;
            backdrop.classList.remove('open');
            modal.classList.remove('open');
        };

        modal.querySelector('#sendConfirmTitle').textContent = TEXT.title;
        modal.querySelector('#sendConfirmMessage').textContent = TEXT.message;
        modal.querySelector('#sendConfirmCountLabel').textContent = TEXT.countTitle;
        modal.querySelector('#sendConfirmCount').textContent = '0' + TEXT.itemUnit;
        modal.querySelector('#sendConfirmHelp').textContent = TEXT.help;
        modal.querySelector('#sendConfirmCancel').textContent = TEXT.cancel;
        modal.querySelector('#sendConfirmSubmit').textContent = TEXT.send;

        backdrop.addEventListener('click', close);
        modal.querySelector('#sendConfirmCancel').addEventListener('click', close);
        modal.querySelector('#sendConfirmSubmit').addEventListener('click', () => {
            if (!pendingForm) return;
            pendingForm.dataset.confirmed = '1';

            const approved = pendingForm.querySelector('input[name="final_approved"]');
            if (approved) approved.value = '1';

            if (pendingSubmitter && pendingSubmitter.name) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = pendingSubmitter.name;
                hidden.value = pendingSubmitter.value;
                pendingForm.appendChild(hidden);
            }

            const editor = modal.querySelector('#sendConfirmEditor');
            if (editor && !editor.hidden) {
                const templateInput = modal.querySelector('#sendConfirmTemplate');
                const saveInput = modal.querySelector('#sendConfirmSaveTemplate');
                let hiddenTemplate = pendingForm.querySelector('input[name="notice_message_template"]');
                if (!hiddenTemplate) {
                    hiddenTemplate = document.createElement('input');
                    hiddenTemplate.type = 'hidden';
                    hiddenTemplate.name = 'notice_message_template';
                    pendingForm.appendChild(hiddenTemplate);
                }
                hiddenTemplate.value = templateInput ? templateInput.value : '';

                let hiddenSave = pendingForm.querySelector('input[name="save_notice_template"]');
                if (!hiddenSave) {
                    hiddenSave = document.createElement('input');
                    hiddenSave.type = 'hidden';
                    hiddenSave.name = 'save_notice_template';
                    pendingForm.appendChild(hiddenSave);
                }
                hiddenSave.value = saveInput && saveInput.checked ? '1' : '';
            }

            pendingForm.submit();
        });

        return {backdrop, modal};
    }

    document.addEventListener('submit', (event) => {
        const submitter = event.submitter || document.activeElement;
        const trigger = submitter && submitter.classList && submitter.classList.contains('send-confirm-trigger');
        const form = event.target.closest('.send-confirm-form') || (trigger ? event.target : null);
        if (!form || form.dataset.confirmed === '1') return;

        event.preventDefault();
        const count = selectedCount(form, submitter);
        if (!count.ok) {
            alert(form.dataset.emptyMessage || TEXT.empty);
            return;
        }

        pendingForm = form;
        pendingSubmitter = submitter && submitter.name ? submitter : null;

        const {backdrop, modal} = ensureSendConfirm();
        const items = summaryItems(form, submitter);
        const summary = modal.querySelector('#sendConfirmSummary');
        summary.innerHTML = '';
        summary.hidden = items.length === 0;
        items.forEach((item) => {
            const li = document.createElement('li');
            li.className = 'is-' + item.type;
            li.textContent = item.text;
            summary.appendChild(li);
        });

        modal.querySelector('#sendConfirmTitle').textContent = (submitter && submitter.dataset.sendTitle) || form.dataset.sendTitle || TEXT.title;
        modal.querySelector('#sendConfirmMessage').textContent = (submitter && submitter.dataset.sendMessage) || form.dataset.sendMessage || TEXT.message;
        modal.querySelector('#sendConfirmCount').textContent = count.text;
        modal.querySelector('#sendConfirmCountLabel').textContent = form.dataset.sendCountTitle || TEXT.countTitle;
        modal.querySelector('#sendConfirmHelp').textContent = form.dataset.sendHelp || TEXT.help;
        modal.querySelector('#sendConfirmSubmit').textContent = (submitter && submitter.dataset.sendSubmitLabel) || form.dataset.sendSubmitLabel || TEXT.send;

        const editor = modal.querySelector('#sendConfirmEditor');
        const templateInput = modal.querySelector('#sendConfirmTemplate');
        const saveInput = modal.querySelector('#sendConfirmSaveTemplate');
        const tokens = modal.querySelector('#sendConfirmTokens');
        const previewWrap = modal.querySelector('#sendConfirmPreviewWrap');
        const preview = modal.querySelector('#sendConfirmPreview');
        const editable = form.dataset.messageEditable === '1' || (submitter && submitter.dataset.messageEditable === '1');
        if (editor) {
            editor.hidden = !editable;
        }
        if (previewWrap) {
            previewWrap.hidden = !editable;
        }
        if (editable && templateInput) {
            const values = parseMessageValues(form);
            const updatePreview = () => {
                if (!preview) return;
                const rendered = renderMessagePreview(templateInput.value, values) || form.dataset.messagePreview || '';
                preview.textContent = rendered;
            };
            templateInput.value = humanizeTemplate(form.dataset.messageTemplate || '');
            templateInput.oninput = updatePreview;
            if (saveInput) {
                saveInput.checked = false;
            }
            if (tokens) {
                tokens.textContent = form.dataset.messageTokens || '\uB3C4\uC7A5\uBA85, \uD559\uC0DD\uBA85, \uCCAD\uAD6C\uC6D4, \uAE08\uC561, \uB0A9\uBD80\uC77C\uC740 \uC790\uB3D9\uC73C\uB85C \uBC14\uB00C\uB2C8\uB2E4.';
            }
            updatePreview();
        }

        backdrop.classList.add('open');
        modal.classList.add('open');
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        const backdrop = document.getElementById('sendConfirmBackdrop');
        const modal = document.getElementById('sendConfirmModal');
        if (backdrop) backdrop.classList.remove('open');
        if (modal) modal.classList.remove('open');
        pendingForm = null;
        pendingSubmitter = null;
    });
})();
