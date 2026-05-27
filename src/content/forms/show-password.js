document.querySelectorAll('a[data-js="show-password"]').forEach(element => {
    const input = element.previousElementSibling

    if ( ! input.value) {
        element.hidden = true
        return
    }

    element.addEventListener('click', event => {
        event.preventDefault()
        element.title = input.type === 'password' ? 'Hide Password' : 'Show Password'
        input.type = input.type === 'password' ? 'text' : 'password'
    })
})
