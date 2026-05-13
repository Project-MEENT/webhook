const pre = document.createElement('pre')
const output = document.createElement('code')
pre.appendChild(output)
document.querySelector('output').insertAdjacentElement('afterbegin', pre)

document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', async (event) => {
        event.preventDefault()

        document.querySelectorAll('[data-js="response-link"]').forEach(link => link.remove())
        output.textContent = ''

        form.style.display = 'none'
        form.insertAdjacentHTML('afterend', '<a data-js="response-link" href="/">\u2190 Try again</a>')

        const isRegisterRequest = form.dataset.js === 'register-form'
        const data = isRegisterRequest
            ? form.querySelector('input[name="webid"]').value.trim()
            : form.querySelector('textarea').value

        const headers = {
            'Accept': 'application/json',
            'Content-Type': isRegisterRequest ? 'text/plain' : 'application/json',
        }

        let apiKey
        form.querySelectorAll('[data-js="headers"] input[name]').forEach(input => {
            headers[input.name] = input.value

            if (input.name === 'Authorization') {
                apiKey = input.value.trim().split(' ')[1]
            }
        })

        let url = form.action || window.location.href

        const version = form.querySelector('select[name="api-version"]').value
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
