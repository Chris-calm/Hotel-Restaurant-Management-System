<div id="errorModal" class="hidden fixed inset-0 bg-black/20 backdrop-blur-sm z-[9999] flex items-center justify-center">
    <div class="bg-white w-[90%] max-w-md rounded-2xl shadow-2xl p-8 relative animate-slideIn">
        <div class="flex justify-center mb-6">
            <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center">
                <i class='bx bx-x text-3xl text-red-600'></i>
            </div>
        </div>

        <div class="text-center">
            <h3 class="text-xl font-semibold text-gray-800 mb-2" id="errorTitle">Error!</h3>
            <p class="text-gray-600 mb-6" id="errorMessage">An error occurred. Please try again.</p>

            <button id="errorModalClose" class="bg-red-600 hover:bg-red-700 text-white px-6 py-2.5 rounded-xl font-medium transition-all duration-200 transform hover:scale-105">
                Try Again
            </button>
        </div>
    </div>
</div>

<script>
function showErrorModal(title, message, callback = null) {
    const modal = document.getElementById('errorModal');
    const titleEl = document.getElementById('errorTitle');
    const messageEl = document.getElementById('errorMessage');
    const closeBtn = document.getElementById('errorModalClose');

    titleEl.textContent = title;
    messageEl.textContent = message;
    modal.classList.remove('hidden');

    closeBtn.onclick = () => {
        modal.classList.add('hidden');
        if (callback) callback();
    };

    setTimeout(() => {
        if (!modal.classList.contains('hidden')) {
            modal.classList.add('hidden');
            if (callback) callback();
        }
    }, 5000);
}

document.getElementById('errorModal')?.addEventListener('click', (e) => {
    if (e.target.id === 'errorModal') {
        document.getElementById('errorModal').classList.add('hidden');
    }
});
</script>
