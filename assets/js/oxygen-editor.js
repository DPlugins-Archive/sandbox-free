document.addEventListener("DOMContentLoaded", async () => {

    await new Promise(resolve => {
        let waitingForScope = setInterval(() => {
            let _scope = angular.element(window.top.document.body).scope();

            if (
                _scope !== undefined
                && _scope.iframeScope !== false
            ) {
                clearInterval(waitingForScope);
                resolve();
            }
        }, 1000);
    });

    const save_button = document.querySelector('div.oxygen-save-button');

    save_button.querySelector('span').innerHTML = 'Save Sandbox';

    save_button.addEventListener('mouseover', (event) => {
        save_button.querySelector('span').innerHTML = sandbox.session.name;
    });
    save_button.addEventListener('mouseout', (event) => {
        save_button.querySelector('span').innerHTML = 'Save Sandbox';
    });
});
