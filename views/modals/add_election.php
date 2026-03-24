<?php
// views/modals/add_election.php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
?>

<div id="add-election-modal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-50 transition-all duration-300">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg transform transition-all duration-300 scale-95 opacity-0 modal-content">
        <!-- Header with gradient -->
        <div class="bg-gradient-to-r from-pink-900 to-pink-700 px-6 py-4 rounded-t-xl">
            <div class="flex justify-between items-center">
                <h3 class="text-xl font-bold text-white flex items-center" id="modal-title">
                    <i class="fas fa-plus-circle mr-2"></i>
                    <span>Add New Election</span>
                </h3>
                <button type="button" onclick="hideModal('add-election-modal')" class="text-white hover:text-pink-200 transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>
        
        <!-- Form -->
        <form id="election-form" class="p-6 space-y-5">
            <input type="hidden" id="election-id" name="id">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            
            <!-- Title Field -->
            <div>
                <label for="election-title" class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-heading text-pink-600 mr-1"></i> Election Title <span class="text-red-500">*</span>
                </label>
                <input type="text" id="election-title" name="title" required 
                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-transparent transition-all"
                       placeholder="e.g., Prefectorial Election 2025">
                <p class="text-xs text-gray-500 mt-1">Choose a descriptive title for the election</p>
            </div>
            
            <!-- Date Fields -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="start-date" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-play text-green-600 mr-1"></i> Start Date <span class="text-red-500">*</span>
                    </label>
                    <input type="datetime-local" id="start-date" name="start_date" required 
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-transparent transition-all">
                    <p class="text-xs text-gray-500 mt-1">When voting begins</p>
                </div>
                <div>
                    <label for="end-date" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-stop text-red-600 mr-1"></i> End Date <span class="text-red-500">*</span>
                    </label>
                    <input type="datetime-local" id="end-date" name="end_date" required 
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-transparent transition-all">
                    <p class="text-xs text-gray-500 mt-1">When voting ends</p>
                </div>
            </div>
            
            <!-- Description Field -->
            <div>
                <label for="election-description" class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-align-left text-blue-600 mr-1"></i> Description
                </label>
                <textarea id="election-description" name="description" rows="4" 
                          class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-transparent transition-all resize-none"
                          placeholder="Provide details about this election..."></textarea>
                <p class="text-xs text-gray-500 mt-1">Optional: Add context or special instructions</p>
            </div>
            
            <!-- Error Message Container -->
            <div id="form-error" class="hidden p-4 bg-red-50 border-l-4 border-red-500 text-red-700 rounded-lg flex items-center">
                <i class="fas fa-exclamation-circle mr-3 text-xl"></i>
                <span class="flex-1"></span>
                <button type="button" onclick="this.parentElement.classList.add('hidden')" class="text-red-500 hover:text-red-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <!-- Success Message Container -->
            <div id="form-success" class="hidden p-4 bg-green-50 border-l-4 border-green-500 text-green-700 rounded-lg flex items-center">
                <i class="fas fa-check-circle mr-3 text-xl"></i>
                <span class="flex-1"></span>
                <button type="button" onclick="this.parentElement.classList.add('hidden')" class="text-green-500 hover:text-green-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <!-- Form Actions -->
            <div class="flex justify-end space-x-3 pt-4 border-t border-gray-200">
                <button type="button" onclick="hideModal('add-election-modal')" 
                        class="px-6 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-all flex items-center">
                    <i class="fas fa-times mr-2"></i> Cancel
                </button>
                <button type="submit" id="save-election-btn"
                        class="px-6 py-2.5 bg-pink-900 hover:bg-pink-800 text-white rounded-lg transition-all shadow-md hover:shadow-lg flex items-center">
                    <i class="fas fa-save mr-2"></i>
                    <span>Save Election</span>
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* Modal animations */
#add-election-modal {
    backdrop-filter: blur(4px);
    transition: opacity 0.3s ease;
}

#add-election-modal.hidden {
    opacity: 0;
    pointer-events: none;
}

#add-election-modal:not(.hidden) .modal-content {
    transform: scale(1);
    opacity: 1;
}

.modal-content {
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    max-height: 90vh;
    overflow-y: auto;
}

/* Custom scrollbar for modal */
.modal-content::-webkit-scrollbar {
    width: 8px;
}

.modal-content::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.modal-content::-webkit-scrollbar-thumb {
    background: #831843;
    border-radius: 10px;
}

.modal-content::-webkit-scrollbar-thumb:hover {
    background: #9d174d;
}

/* Form field focus effects */
input:focus, textarea:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(131, 24, 67, 0.1);
}

/* Loading spinner */
.btn-loading {
    position: relative;
    pointer-events: none;
    opacity: 0.7;
}

.btn-loading i {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* Date input styling */
input[type="datetime-local"] {
    color-scheme: light;
}

/* Responsive adjustments */
@media (max-width: 640px) {
    .modal-content {
        margin: 1rem;
        max-height: calc(100vh - 2rem);
        width: calc(100% - 2rem);
    }
    
    .grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
// Modal functionality - these functions will be available globally
window.hideModal = function(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
        
        // Log modal close if ActivityLogger exists
        if (window.ActivityLogger) {
            ActivityLogger.log('modal_closed', 'Election modal closed');
        }
    }
};

window.showModal = function(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        
        // Log modal open if ActivityLogger exists
        if (window.ActivityLogger && modalId === 'add-election-modal') {
            const isEdit = document.getElementById('election-id')?.value;
            ActivityLogger.log(
                isEdit ? 'edit_modal_opened' : 'add_modal_opened',
                isEdit ? 'Edit election modal opened' : 'Add election modal opened',
                isEdit ? { election_id: document.getElementById('election-id').value } : {}
            );
        }
    }
};

// Format date for datetime-local input
window.formatDateTimeLocal = function(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${year}-${month}-${day}T${hours}:${minutes}`;
};

// Reset form for adding new election
window.resetElectionForm = function() {
    const form = document.getElementById('election-form');
    const idInput = document.getElementById('election-id');
    const titleInput = document.getElementById('election-title');
    const modalTitle = document.querySelector('#modal-title span');

    if (form) form.reset();
    if (idInput) idInput.value = '';
    if (modalTitle) modalTitle.textContent = 'Add New Election';

    // Pre-fill title with current academic year so admin just adds event name
    if (titleInput) {
        const year = (typeof CURRENT_ACADEMIC_YEAR !== 'undefined' && CURRENT_ACADEMIC_YEAR)
            ? CURRENT_ACADEMIC_YEAR
            : new Date().getFullYear();
        titleInput.value = year + ' ';
        // Move cursor to end so admin can type right after the year
        setTimeout(() => {
            titleInput.focus();
            titleInput.setSelectionRange(titleInput.value.length, titleInput.value.length);
        }, 50);
    }

    // Set default dates
    const now = new Date();
    const tomorrow = new Date(now);
    tomorrow.setDate(tomorrow.getDate() + 1);

    const startInput = document.getElementById('start-date');
    const endInput = document.getElementById('end-date');

    if (startInput) startInput.value = window.formatDateTimeLocal(now);
    if (endInput) endInput.value = window.formatDateTimeLocal(tomorrow);

    // Hide error/success messages
    const errorDiv = document.getElementById('form-error');
    const successDiv = document.getElementById('form-success');
    if (errorDiv) errorDiv.classList.add('hidden');
    if (successDiv) successDiv.classList.add('hidden');
};

// Populate form for editing
window.populateElectionForm = function(election) {
    const idInput = document.getElementById('election-id');
    const titleInput = document.getElementById('election-title');
    const descInput = document.getElementById('election-description');
    const startInput = document.getElementById('start-date');
    const endInput = document.getElementById('end-date');
    const modalTitle = document.querySelector('#modal-title span');
    
    if (idInput) idInput.value = election.id;
    if (titleInput) titleInput.value = election.title || '';
    if (descInput) descInput.value = election.description || '';
    if (modalTitle) modalTitle.textContent = 'Edit Election';
    
    if (election.start_date && startInput) {
        startInput.value = window.formatDateTimeLocal(new Date(election.start_date.replace(' ', 'T')));
    }
    if (election.end_date && endInput) {
        endInput.value = window.formatDateTimeLocal(new Date(election.end_date.replace(' ', 'T')));
    }
    
    // Hide error/success messages
    const errorDiv = document.getElementById('form-error');
    const successDiv = document.getElementById('form-success');
    if (errorDiv) errorDiv.classList.add('hidden');
    if (successDiv) successDiv.classList.add('hidden');
};

// Initialize modal when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('add-election-modal');
    const form = document.getElementById('election-form');
    const saveBtn = document.getElementById('save-election-btn');
    const errorDiv = document.getElementById('form-error');
    const successDiv = document.getElementById('form-success');
    
    if (!modal || !form) return;
    
    // Set default dates on initial load
    resetElectionForm();
    
    // Form submission handler
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const idInput = document.getElementById('election-id');
        const titleInput = document.getElementById('election-title');
        const descInput = document.getElementById('election-description');
        const startInput = document.getElementById('start-date');
        const endInput = document.getElementById('end-date');
        
        // Validate dates
        const startDate = new Date(startInput.value);
        const endDate = new Date(endInput.value);
        const now = new Date();
        const isEdit = !!idInput.value;
        
        if (endDate <= startDate) {
            showError('End date must be after start date');
            return;
        }
        
        if (!isEdit && startDate <= now) {
            showError('Start date must be in the future for new elections');
            return;
        }
        
        // Prepare data
        const formData = {
            title: titleInput.value,
            description: descInput.value,
            start_date: startInput.value,
            end_date: endInput.value,
            csrf_token: document.querySelector('input[name="csrf_token"]').value
        };
        
        if (isEdit) {
            formData.id = idInput.value;
        }
        
        // Log attempt if ActivityLogger exists
        if (window.ActivityLogger) {
            ActivityLogger.log(
                isEdit ? 'update_attempt' : 'create_attempt',
                isEdit ? 'Attempting to update election' : 'Attempting to create election',
                {
                    election_id: isEdit ? formData.id : null,
                    title: formData.title
                }
            );
        }
        
        // Show loading state
        saveBtn.disabled = true;
        saveBtn.classList.add('btn-loading');
        saveBtn.innerHTML = '<i class="fas fa-spinner mr-2"></i>Saving...';
        
        try {
            const baseUrl = window.BASE_URL || '';
            let url = `${baseUrl}/api/admin/elections.php`;
            let method = isEdit ? 'PUT' : 'POST';
            
            if (isEdit) {
                url = `${baseUrl}/api/admin/elections.php?id=${encodeURIComponent(formData.id)}`;
            }
            
            const response = await fetch(url, {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': document.querySelector('input[name="csrf_token"]').value
                },
                body: JSON.stringify(formData)
            });
            
            // Check if response is OK
            if (!response.ok) {
                const text = await response.text();
                console.error('Server response:', text);
                
                // Try to parse as JSON if possible
                try {
                    const errorData = JSON.parse(text);
                    throw new Error(errorData.message || `Server error: ${response.status}`);
                } catch (e) {
                    throw new Error(`Server error: ${response.status} - ${text.substring(0, 100)}`);
                }
            }
            
            // Check content type
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Non-JSON response:', text);
                throw new Error('Server returned non-JSON response. Check error logs.');
            }
            
            const data = await response.json();
            
            if (data.success) {
                // Log success
                if (window.ActivityLogger) {
                    ActivityLogger.log(
                        isEdit ? 'update_success' : 'create_success',
                        isEdit ? 'Election updated successfully' : 'Election created successfully',
                        {
                            election_id: isEdit ? formData.id : data.election_id,
                            title: formData.title
                        }
                    );
                }
                
                // Show success message
                showSuccess(isEdit ? 'Election updated successfully!' : 'Election created successfully!');
                
                // Close modal after delay
                setTimeout(() => {
                    window.hideModal('add-election-modal');
                    // Reload page to show changes
                    window.location.reload();
                }, 1000);
            } else {
                throw new Error(data.message || 'Failed to save election');
            }
        } catch (error) {
            console.error('Save error:', error);
            
            // Log error
            if (window.ActivityLogger) {
                ActivityLogger.log(
                    isEdit ? 'update_error' : 'create_error',
                    'Error saving election',
                    { error: error.message }
                );
            }
            
            showError(error.message);
        } finally {
            // Reset button state
            saveBtn.disabled = false;
            saveBtn.classList.remove('btn-loading');
            saveBtn.innerHTML = '<i class="fas fa-save mr-2"></i>Save Election';
        }
    });
    
    // Helper to show error
    function showError(message) {
        if (errorDiv) {
            const errorSpan = errorDiv.querySelector('span');
            if (errorSpan) errorSpan.textContent = message;
            errorDiv.classList.remove('hidden');
            
            // Auto hide after 5 seconds
            setTimeout(() => {
                errorDiv.classList.add('hidden');
            }, 5000);
        } else {
            alert('Error: ' + message);
        }
    }
    
    // Helper to show success
    function showSuccess(message) {
        if (successDiv) {
            const successSpan = successDiv.querySelector('span');
            if (successSpan) successSpan.textContent = message;
            successDiv.classList.remove('hidden');
            
            // Auto hide after 3 seconds
            setTimeout(() => {
                successDiv.classList.add('hidden');
            }, 3000);
        }
    }
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
            if (window.ActivityLogger) {
                ActivityLogger.log('modal_closed_escape', 'Modal closed with Escape key');
            }
            window.hideModal('add-election-modal');
        }
    });
    
    // Close modal when clicking outside
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            if (window.ActivityLogger) {
                ActivityLogger.log('modal_closed_outside', 'Modal closed by clicking outside');
            }
            window.hideModal('add-election-modal');
        }
    });
});
</script>