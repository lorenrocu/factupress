document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');
    form.addEventListener('submit', function(e) {
        const clientId = document.getElementById('factuspress_client_id').value;
        const clientSecret = document.getElementById('factuspress_client_secret').value;
        const username = document.getElementById('factuspress_username').value;
        const password = document.getElementById('factuspress_password').value;

        if (!clientId || !clientSecret || !username || !password) {
            e.preventDefault();
            alert('Todos los campos son obligatorios.');
        }
    });
});
