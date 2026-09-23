document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-js="check-solid-connection"]').forEach((element) => {
        checkSolidConnection(element)
    })
})

/**
 * Probe the Solid Pod for a WebID and update the indicator element in place.
 *
 * Can be called at any time (e.g. after auth recovery) to refresh the status
 * shown in the admin table without reloading the page.
 */
function checkSolidConnection(element) {
    const row = element.closest('tr')

    const hasConsent = row.querySelector('input[name="consent"]').checked
    const webId = row.querySelector('a[href]').href

    element.textContent = '⏳'
    element.title = 'Checking ...'

    if (! hasConsent) {
        element.textContent = '__'
        element.title = 'Cannot connect if there is no consent'
        return
    }

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