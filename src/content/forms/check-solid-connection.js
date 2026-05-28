document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-js="check-solid-connection"]').forEach((element) => {
        const row = element.closest('tr')

        const apiKey = row.querySelector('input[name="api-key"]').value
        const dongle = row.querySelector('input[name="dongle"]').checked
        const hasConsent = row.querySelector('input[name="consent"]').checked

        element.textContent = '⏳'
        element.title = 'Checking ...'

        if ( ! hasConsent) {
            element.textContent = '__'
            element.title = 'Can not connect if there is no consent'
        } else if ( dongle) {
            fetch(
                `/api/data/private`,
                { headers: { 'Authorization': 'Bearer ' + apiKey } },
            ).then((response) => {
                element.onclick = async () => { alert(await response.text()) }
                element.title = response.statusText + '(' + response.status + ')'
                element.textContent = response.ok
                ? '✅'
                : '❌'
            }).catch((error) => {
                element.textContent = '⛔'
                element.title = 'Error: ' + (error.message ? error.message : error)
            })
        } else  {
            element.textContent = '__'
            element.title = 'Can not check without API key'
        }
    })
})
