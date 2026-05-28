document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-js="check-solid-connection"]').forEach((element) => {
        const row = element.closest('tr')

        const dongle = row.querySelector('input[name="dongle"]').checked
        const hasConsent = row.querySelector('input[name="consent"]').checked
        const webId = row.querySelector('a[href]').href

        element.textContent = '⏳'
        element.title = 'Checking ...'

        if ( ! hasConsent) {
            element.textContent = '__'
            element.title = 'Can not connect if there is no consent'
        } else {
            fetch(
                `/api/data/MEENT?webid=${encodeURIComponent(webId)}`,
            ).then(async (response) => {
                const message = await response.text()

                element.onclick = () => alert(message)
                element.title = response.statusText + '(' + response.status + ')'
                element.textContent = response.ok
                ? '✅'
                : '❌'
            }).catch((error) => {
                element.textContent = '⛔'
                element.title = 'Error: ' + (error.message ? error.message : error)
            })
        }
    })
})
