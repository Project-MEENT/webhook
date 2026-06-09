/* Change form to submit on checkbox change */
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[action="/admin/manage"]').forEach(form => {
        form.querySelector('button[type="submit"]').hidden = true
        form.querySelector('[data-js="make-admin"]').addEventListener('change', () => form.submit())
    })
})
