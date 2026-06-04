(function () {
    if (window.ieumStudentListModalBound) return;
    window.ieumStudentListModalBound = true;
    document.documentElement.dataset.studentListModal = 'ready';

    function setText(id, value, emptyText) {
        var el = document.getElementById(id);
        if (!el) return;
        var hasValue = value && String(value).trim();
        el.textContent = hasValue ? String(value).trim() : (emptyText || '-');
        el.classList.toggle('detail-empty', !hasValue);
    }

    function renderSignals(signals) {
        var box = document.getElementById('detailSignals');
        if (!box) return;
        box.textContent = '';

        if (!Array.isArray(signals) || signals.length === 0) {
            var empty = document.createElement('span');
            empty.className = 'muted-text';
            empty.textContent = '특이 신호 없음';
            box.appendChild(empty);
            return;
        }

        signals.forEach(function (signal) {
            var badge = document.createElement('span');
            badge.className = 'student-badge ' + (signal.tone || '');
            badge.textContent = signal.label || '-';
            if (signal.action) badge.title = signal.action;
            box.appendChild(badge);
        });
    }

    function setLink(id, url) {
        var el = document.getElementById(id);
        if (!el || !url) return;
        el.href = url;
    }

    function openDetail(detail) {
        var backdrop = document.getElementById('studentDetailBackdrop');
        var modal = document.getElementById('studentDetailModal');
        if (!backdrop || !modal || !detail) return;

        setText('studentDetailTitle', (detail.name || '원생') + ' 원생 프로필');
        setText('studentDetailCode', '원생번호 ' + (detail.code || '-'));
        setText('detailStatus', detail.status);
        setText('detailProgram', detail.program);
        setText('detailGrade', detail.grade);
        setText('detailClassTime', detail.classTime);
        setText('detailAttendanceDays', detail.attendanceDays);
        setText('detailLastAttendance', detail.lastAttendance);
        setText('detailStudentPhone', detail.studentPhone);
        setText('detailTuition', detail.tuition);
        setText('detailAdmissionDate', detail.admissionDate);
        setText('detailPromotionCurrent', detail.promotionCurrent);
        setText('detailPromotionNext', detail.promotionNext);
        setText('detailPromotionNextDate', detail.promotionNextDate);
        setText('detailGuardian', detail.guardian, '등록 없음');
        setText('detailVehicle', detail.vehicle, '차량 이용 없음');
        setText('detailMemo', detail.memo, '메모 없음');
        setText('detailCounselingNote', detail.counselingNote, '상담 메모 없음');
        renderSignals(detail.signals || []);
        setText('detailCarePlan', detail.carePlan, '오늘 특별히 처리할 신호는 없습니다.');
        setText('detailProfileStatus', detail.profileStatus, '최근 상태를 확인할 수 없습니다.');
        setText('detailSmsFlow', detail.smsFlow, '문자 흐름을 확인할 수 없습니다.');
        setText('detailRecentSms', detail.recentSms, '최근 문자 기록 없음');
        setText('detailRecentActivity', detail.recentActivity, '최근 처리 기록 없음');
        var contactsLink = document.getElementById('detailContactsLink');
        if (contactsLink && detail.contactsUrl) {
            contactsLink.href = detail.contactsUrl;
        }
        setLink('detailAttendanceLink', detail.attendanceUrl);
        setLink('detailTuitionLink', detail.tuitionUrl);
        setLink('detailSmsLink', detail.smsUrl);
        setLink('detailReportLink', detail.reportUrl);
        setLink('detailVehicleLink', detail.vehicleUrl);

        modal.dataset.editUrl = detail.editUrl || '#';
        modal.dataset.studentName = detail.name || '';
        document.querySelectorAll('.student-detail-edit-action').forEach(function (edit) {
            edit.href = detail.editUrl || '#';
            edit.dataset.editUrl = detail.editUrl || '#';
            edit.dataset.studentName = detail.name || '원생';
        });

        backdrop.hidden = false;
        modal.hidden = false;
        requestAnimationFrame(function () {
            backdrop.classList.add('open');
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
        });
    }

    function closeDetail() {
        var backdrop = document.getElementById('studentDetailBackdrop');
        var modal = document.getElementById('studentDetailModal');
        if (!backdrop || !modal) return;
        backdrop.classList.remove('open');
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        window.setTimeout(function () {
            if (!backdrop.classList.contains('open')) backdrop.hidden = true;
            if (!modal.classList.contains('open')) modal.hidden = true;
        }, 180);
    }

    function openEdit(link) {
        if (!link || document.body.classList.contains('embed-page')) return;
        closeDetail();
        var backdrop = document.getElementById('studentEditBackdrop');
        var modal = document.getElementById('studentEditModal');
        var frame = document.getElementById('studentEditFrame');
        var title = document.getElementById('studentEditTitle');
        if (!backdrop || !modal || !frame) return;

        var detailModal = document.getElementById('studentDetailModal');
        var editUrl = link.dataset.editUrl || (detailModal ? detailModal.dataset.editUrl : '') || link.getAttribute('href') || '';
        var target = new URL(editUrl, window.location.href);
        target.searchParams.set('embed', '1');
        if (title) title.textContent = (link.dataset.studentName || '원생') + ' 정보 수정';

        window.ieumStudentEditDirty = false;
        frame.src = target.toString();
        backdrop.hidden = false;
        modal.hidden = false;
        requestAnimationFrame(function () {
            backdrop.classList.add('open');
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
        });
        frame.onload = function () {
            try {
                var doc = frame.contentDocument;
                if (doc && doc.querySelector('.notice.ok')) window.ieumStudentEditDirty = true;
            } catch (error) {}
        };
    }

    function closeEdit() {
        var backdrop = document.getElementById('studentEditBackdrop');
        var modal = document.getElementById('studentEditModal');
        var frame = document.getElementById('studentEditFrame');
        if (!backdrop || !modal) return;
        backdrop.classList.remove('open');
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        window.setTimeout(function () {
            if (!backdrop.classList.contains('open')) backdrop.hidden = true;
            if (!modal.classList.contains('open')) modal.hidden = true;
            if (frame) frame.src = 'about:blank';
            if (window.ieumStudentEditDirty) window.location.reload();
        }, 180);
    }

    window.ieumOpenStudentDetailFromButton = function (button) {
        if (!button) return true;
        try {
            openDetail(JSON.parse(button.dataset.detail || '{}'));
        } catch (error) {
            console.error(error);
        }
        return false;
    };

    window.ieumOpenStudentEditFromLink = function (link) {
        openEdit(link);
        return false;
    };

    document.addEventListener('click', function (event) {
        var detailButton = event.target.closest('.student-detail-open');
        if (detailButton) {
            document.documentElement.dataset.studentListLastClick = 'detail';
            event.preventDefault();
            event.stopPropagation();
            window.ieumOpenStudentDetailFromButton(detailButton);
            return;
        }

        var editLink = event.target.closest('.student-edit-modal-open');
        if (editLink && !document.body.classList.contains('embed-page')) {
            document.documentElement.dataset.studentListLastClick = 'edit';
            event.preventDefault();
            event.stopPropagation();
            openEdit(editLink);
            return;
        }

        if (event.target.closest('#studentDetailClose, #studentDetailCloseBottom') || event.target.id === 'studentDetailBackdrop') {
            event.preventDefault();
            closeDetail();
            return;
        }

        if (event.target.closest('#studentEditClose') || event.target.id === 'studentEditBackdrop') {
            event.preventDefault();
            closeEdit();
        }
    }, true);

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeEdit();
            closeDetail();
        }
    });
})();
