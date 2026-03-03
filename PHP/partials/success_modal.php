<div id="successModal" class="hidden fixed inset-0 bg-black/20 backdrop-blur-sm z-[9999] flex items-center justify-center">
    <div class="bg-white w-[90%] max-w-md rounded-2xl shadow-2xl p-8 relative animate-slideIn">
        <div class="flex justify-center mb-6">
            <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center">
                <i class='bx bx-check text-3xl text-green-600'></i>
            </div>
        </div>

        <div class="text-center">
            <h3 class="text-xl font-semibold text-gray-800 mb-2" id="successTitle">Success!</h3>
            <p class="text-gray-600 mb-6" id="successMessage">Operation completed successfully.</p>

            <button id="successModalClose" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2.5 rounded-xl font-medium transition-all duration-200 transform hover:scale-105">
                Continue
            </button>
        </div>
    </div>
</div>

<style>
@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-20px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}
.animate-slideIn {
    animation: slideIn 0.3s ease-out;
}
</style>

<script>
function showSuccessModal(title, message, callback = null) {
    const modal = document.getElementById('successModal');
    const titleEl = document.getElementById('successTitle');
    const messageEl = document.getElementById('successMessage');
    const closeBtn = document.getElementById('successModalClose');

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
    }, 3000);
}

document.getElementById('successModal')?.addEventListener('click', (e) => {
    if (e.target.id === 'successModal') {
        document.getElementById('successModal').classList.add('hidden');
    }
});
</script>
