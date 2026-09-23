document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-js="recover-auth"]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault()

            const button = form.querySelector('button[type="submit"]')
            const originalLabel = button.textContent
            button.textContent = '...'
            button.disabled = true

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: { 'Accept': 'application/json' },
                })

                const data = await response.json().catch(() => ({}))

                if (response.ok) {
                    button.textContent = '✅'
                    button.title = data.message || 'Auth recovered'

                    // Refresh the "Connected to Pod" indicator for this row now
                    // that the grant has been re-issued.
                    const row = form.closest('tr')
                    const connectionIndicator = row?.querySelector('[data-js="check-solid-connection"]')
                    if (connectionIndicator) {
                        checkSolidConnection(connectionIndicator)
                    }
                } else {
                    button.textContent = '❌'
                    button.title = (data.title || 'Recovery failed') + ': ' + (data.detail || data.message || 'Unknown error')
                }
            } catch (error) {
                button.textContent = '⛔'
                button.title = 'Error: ' + (error.message || error)
            } finally {
                setTimeout(() => {
                    button.textContent = originalLabel
                    button.disabled = false
                }, 2000)
            }
        })
    })
})