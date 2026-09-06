const pre = document.createElement('pre')
const output = document.createElement('code')
pre.appendChild(output)
document.querySelector('output').insertAdjacentElement('afterbegin', pre)

document.querySelectorAll('form:not([data-js="consent-form"])').forEach(form => {
    form.addEventListener('submit', async (event) => {
        event.preventDefault()

        document.querySelectorAll('[data-js="response-link"]').forEach(link => link.remove())
        output.textContent = ''

        form.style.display = 'none'
        form.insertAdjacentHTML('afterend', '<a data-js="response-link" href="">\u2190 Try again</a>')

        let contentType = 'application/json'
        let selectors = 'textarea'

        if (form.dataset.js === 'register-form'){
            contentType = 'text/plain'
            selectors = 'input[name="webid"]'
        }

        if (form.dataset.js === 'pod-creation-form'){
            contentType = 'text/plain'
            selectors = 'input[name="mac"]'
        }

        const input = form.querySelector(selectors)
        const data = input?.value?.trim() || ''

        const headers = {
            'Accept': 'application/json',
            'Content-Type': contentType,
        }

        let apiKey
        form.querySelectorAll('[data-js="headers"] input[name]').forEach(input => {
            headers[input.name] = input.value

            if (input.name === 'Authorization') {
                apiKey = input.value.trim().split(' ')[1]
            }
        })

        let url = form.action || window.location.href

        const versionSelect = form.querySelector('select[name="api-version"]')
        const version = versionSelect?.value
        if (version) {
            url = url.replace(/\/api\/?/, `/api/${version}/`)
        }

        try {
            const response = await fetch(url, {
                body: data,
                headers: headers,
                method: form.method || 'POST',
            })

            const responseText = await response.text()

            output.insertAdjacentHTML('beforeend',
                `Status: ${response.status}:\nResponse:\n${responseText}`,
            )

            try {
                const json = JSON.parse(responseText)

                // Add a link to the data
                if (response.headers.get('Location')) {
                    let url = new URL(response.headers.get('Location'))

                    const localUrl = new URL(window.location.href)
                    if (url.origin !== localUrl.origin) {
                        // If the URL is in the Solid Pod we need to patch it
                        const pathName = `${localUrl.pathname}/${url.pathname}`
                        url = localUrl
                        // Squash duplicate slashes
                        url.pathname = pathName.replace(/\/{2,}/g, '/')
                    }

                    if (apiKey) {
                        url.searchParams.set('api-key', apiKey)
                    }

                    output.insertAdjacentHTML('afterend', `<a data-js="response-link" href="${url.toString()}">View stored data</a>`)
                }

                // Check if there is an RFC-9457 compliant error
                const hash = json.errors?.[0]?.pointer?.substring(1)
                if (hash) {
                    output.insertAdjacentHTML('afterend', `<a data-js="response-link" href="/errors/#${hash}">See error details</a>`)
                }
            } catch (error) {
                // ignore, not JSON
                console.error(error)
            }
        } catch (error) {
            output.innerHTML = `Error! Could not make request: ${error.message ?? error}`
        }
    })
})
