document.addEventListener("DOMContentLoaded", function () {

    const do_ajax = async (postData) => {
        await fetch(sandbox.ajax_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams(postData)
        })
            .then(response => response.json())
            .then(data => {
                let level = data.success === true ? 'success' : 'error';
                let msg = data.data;

                let notice = document.createElement('div');
                notice.id = 'sandbox-notice';
                notice.className = `notice is-dismissible notice-${level}`;

                let paragraph = document.createElement('p');
                paragraph.innerHTML = msg;
                notice.appendChild(paragraph);

                document.querySelector('hr.wp-header-end').after(notice);
            })
            .catch((error) => {
                console.error('Error:', error);
            });
    }

    document.querySelectorAll('.sandbox-card').forEach(rootNode => {

        rootNode.querySelector('input').addEventListener('click', function (event) {
            try {
                document.querySelector('#sandbox-notice').remove();
            } catch (error) { }

            do_ajax({
                _wpnonce: sandbox.nonce,
                action: `${sandbox.module_id}_update_session`,
                session: rootNode.dataset.id
            });

        });

        if (rootNode.dataset.id === 'false') {
            return;
        }

        let change_name_node = rootNode.querySelector('div.card-content > div.sandbox-description-wrap > input.sandbox-description');

        change_name_node.addEventListener('focus', function (event) {
            let title = rootNode.querySelector('div.card-content > h2 > label').textContent;
            event.target.value = title;
        });

        change_name_node.addEventListener('blur', async function (event) {
            let title = rootNode.querySelector('div.card-content > h2 > label').textContent;

            if (event.target.value !== title) {
                try {
                    document.querySelector('#sandbox-notice').remove();
                } catch (error) { }

                await do_ajax({
                    _wpnonce: sandbox.nonce,
                    action: `${sandbox.module_id}_rename_session`,
                    session: rootNode.dataset.id,
                    new_name: event.target.value
                });

                rootNode.querySelector('div.card-content > h2 > label').textContent = event.target.value;
            }

            event.target.value = '';
        });

        rootNode.querySelector('div.card-content > div.actions > a.delete-button').addEventListener('click', function (event) {
            event.preventDefault();

            let title = rootNode.querySelector('div.card-content > h2 > label').textContent;

            if (confirm(`DELETE the ${title} session?\nThis action cannot be undone.\n'Cancel' to stop, 'OK' to delete.`)) {
                window.location = event.target.href;
            }
        });

        rootNode.querySelector('div.card-content > div.actions > a.publish-button').addEventListener('click', function (event) {
            event.preventDefault();

            let title = rootNode.querySelector('div.card-content > h2 > label').textContent;

            if (confirm(`🔥 PUBLISH the ${title} session?\nThis action cannot be undone.\n'Cancel' to stop, 'OK' to publish.`)) {
                window.location = event.target.href;
            }
        });
    });

    document.querySelector('#import-session-btn').addEventListener('click', function (event) {
        const xstyle = document.getElementById('upload-sandbox-session');
        xstyle.style.display = xstyle.style.display === 'none' ? 'block' : 'none';
    });

});
