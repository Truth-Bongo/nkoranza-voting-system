<?php
// views/modals/add_candidate.php
if (session_status() === PHP_SESSION_NONE) session_start();
$csrf = $_SESSION['csrf_token'] ?? '';

// Load users for dropdown
$db = Database::getInstance()->getConnection();
$users = $db->query("SELECT id, first_name, last_name, department, level FROM users WHERE is_admin = 0 ORDER BY first_name, last_name")->fetchAll(PDO::FETCH_ASSOC);
?>

<div id="add-candidate-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50 transition-all duration-300">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl transform transition-all duration-300 scale-95 opacity-0 modal-content">
        <!-- Header with gradient -->
        <div class="bg-gradient-to-r from-pink-900 to-pink-700 px-6 py-4 rounded-t-xl">
            <div class="flex justify-between items-center">
                <h3 class="text-xl font-bold text-white flex items-center" id="candidate-modal-title">
                    <i class="fas fa-user-plus mr-2"></i>
                    <span>Add New Candidate</span>
                </h3>
                <button type="button" onclick="hideModal('add-candidate-modal')" class="text-white hover:text-pink-200 transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>

        <!-- Form -->
        <form id="candidate-form" enctype="multipart/form-data" class="p-6 space-y-5">
            <input type="hidden" id="candidate-id" name="id">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

            <!-- User Selection -->
            <div>
                <label for="candidate-user" class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-user text-pink-600 mr-1"></i> Select User <span class="text-red-500">*</span>
                </label>
                <select id="candidate-user" name="user_id" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-transparent transition-all" required>
                    <option value="">-- Select User --</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= $user['id'] ?>" data-department="<?= htmlspecialchars($user['department']) ?>" data-level="<?= htmlspecialchars($user['level']) ?>">
                            <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?> 
                            (<?= htmlspecialchars($user['department'] ?? 'No Dept') ?>, <?= htmlspecialchars($user['level'] ?? 'N/A') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-500 mt-1">Select the student who will be a candidate</p>
            </div>

            <!-- Election Selection -->
            <div>
                <label for="candidate-election" class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-calendar-alt text-green-600 mr-1"></i> Election <span class="text-red-500">*</span>
                </label>
                <select id="candidate-election" name="election_id" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-transparent transition-all" required>
                    <option value="">Select Election</option>
                </select>
                <p id="candidate-election-help" class="text-xs text-gray-500 mt-1 hidden"></p>
            </div>

            <!-- Position Selection -->
            <div>
                <label for="candidate-position" class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-briefcase text-purple-600 mr-1"></i> Position <span class="text-red-500">*</span>
                </label>
                <select id="candidate-position" name="position_id" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-transparent transition-all" required>
                    <option value="">Select Position</option>
                </select>
                <p id="candidate-position-help" class="text-xs text-gray-500 mt-1 hidden"></p>
            </div>

            <!-- Manifesto -->
            <div>
                <label for="candidate-description" class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-quote-right text-blue-600 mr-1"></i> Manifesto
                </label>
                <textarea id="candidate-description" name="manifesto" rows="4" 
                          class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-transparent transition-all resize-none"
                          placeholder="Enter candidate's manifesto, promises, or campaign message..."></textarea>
                <p class="text-xs text-gray-500 mt-1">Optional: What the candidate stands for</p>
            </div>

            <!-- Photo Upload -->
            <div>
                <label for="candidate-photo" class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-camera text-yellow-600 mr-1"></i> Photo
                </label>
                <div class="flex items-center space-x-4">
                    <div class="flex-shrink-0">
                        <div class="w-16 h-16 rounded-full bg-gray-200 flex items-center justify-center overflow-hidden border-2 border-gray-300" id="photo-preview-container">
                            <img id="photo-preview" src="<?= BASE_URL ?>/assets/img/default-avatar.png" alt="Preview" class="w-full h-full object-cover hidden">
                            <i class="fas fa-user text-gray-400 text-2xl" id="photo-placeholder"></i>
                        </div>
                    </div>
                    <div class="flex-1">
                        <input type="file" id="candidate-photo" name="photo" accept="image/jpeg,image/png,image/gif,image/webp" 
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-transparent transition-all">
                        <p class="text-xs text-gray-500 mt-1">Optional: Upload a photo (JPEG, PNG, GIF, WebP. Max 2MB)</p>
                    </div>
                </div>
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
                <button type="button" onclick="hideModal('add-candidate-modal')" 
                        class="px-6 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-all flex items-center">
                    <i class="fas fa-times mr-2"></i> Cancel
                </button>
                <button type="submit" id="save-candidate-btn"
                        class="px-6 py-2.5 bg-pink-900 hover:bg-pink-800 text-white rounded-lg transition-all shadow-md hover:shadow-lg flex items-center">
                    <i class="fas fa-save mr-2"></i>
                    <span>Save Candidate</span>
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* Modal animations */
#add-candidate-modal {
    backdrop-filter: blur(4px);
    transition: opacity 0.3s ease;
}

#add-candidate-modal.hidden {
    opacity: 0;
    pointer-events: none;
}

#add-candidate-modal:not(.hidden) .modal-content {
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
input:focus, select:focus, textarea:focus {
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

/* Responsive adjustments */
@media (max-width: 640px) {
    .modal-content {
        margin: 1rem;
        max-height: calc(100vh - 2rem);
        width: calc(100% - 2rem);
    }
}
</style>

<script>
// Make functions globally available
window.candidateModalFunctions = {
    BASE_URL: "<?= BASE_URL ?>",
    CSRF_TOKEN: "<?= $_SESSION['csrf_token'] ?? '' ?>",
    
    init: function() {
        this.modal = document.getElementById('add-candidate-modal');
        this.form = document.getElementById('candidate-form');
        this.saveBtn = document.getElementById('save-candidate-btn');
        this.errorDiv = document.getElementById('form-error');
        this.successDiv = document.getElementById('form-success');
        this.modalTitle = document.getElementById('candidate-modal-title').querySelector('span');
        this.electionSelect = document.getElementById('candidate-election');
        this.positionSelect = document.getElementById('candidate-position');
        this.userSelect = document.getElementById('candidate-user');
        this.photoInput = document.getElementById('candidate-photo');
        this.photoPreview = document.getElementById('photo-preview');
        this.photoPlaceholder = document.getElementById('photo-placeholder');
        this.photoPreviewContainer = document.getElementById('photo-preview-container');
        
        this.setupEventListeners();
        this.loadElections();
        
        // Define the global editCandidate function here
        window.editCandidate = (candidate) => this.editCandidate(candidate);
    },
    
    setupEventListeners: function() {
        // Election change
        this.electionSelect.addEventListener('change', () => {
            this.loadPositions(this.electionSelect.value);
        });
        
        // Photo preview
        if (this.photoInput) {
            this.photoInput.addEventListener('change', (e) => this.handlePhotoPreview(e));
        }
        
        // Form submission
        this.form.addEventListener('submit', (e) => this.handleSubmit(e));
    },
    
    handlePhotoPreview: function(e) {
        const file = e.target.files[0];
        if (file) {
            if (file.size > 2 * 1024 * 1024) {
                this.showError('File size must be less than 2MB');
                this.photoInput.value = '';
                return;
            }
            
            const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!validTypes.includes(file.type)) {
                this.showError('Invalid file type. Please upload JPEG, PNG, GIF, or WebP.');
                this.photoInput.value = '';
                return;
            }
            
            const reader = new FileReader();
            reader.onload = (e) => {
                this.photoPreview.src = e.target.result;
                this.photoPreview.classList.remove('hidden');
                this.photoPlaceholder.classList.add('hidden');
            };
            reader.readAsDataURL(file);
        } else {
            this.photoPreview.classList.add('hidden');
            this.photoPlaceholder.classList.remove('hidden');
        }
    },
    
    toArray: function(payload, ...keys) {
        if (Array.isArray(payload)) return payload;
        if (!payload || typeof payload !== 'object') return [];
        for (const k of keys) if (Array.isArray(payload[k])) return payload[k];
        if (Array.isArray(payload.data)) return payload.data;
        return [];
    },
    
    loadElections: async function() {
        this.electionSelect.innerHTML = '<option value="">Select Election</option>';
        try {
            const res = await fetch(`${this.BASE_URL}/api/voting/elections.php`, { 
                credentials: 'same-origin' 
            });
            const data = await res.json();
            const arr = this.toArray(data, 'elections');
            for (const e of arr) {
                const opt = document.createElement('option');
                opt.value = e.id;
                opt.textContent = e.title + (e.status ? ` (${e.status})` : '');
                opt.dataset.status = e.status || '';
                this.electionSelect.appendChild(opt);
            }
        } catch (e) { 
            console.error('Failed to load elections:', e);
            this.showError('Failed to load elections. Please refresh the page.');
        }
    },
    
    loadPositions: async function(electionId) {
        this.positionSelect.innerHTML = '<option value="">Select Position</option>';
        if (!electionId) return;
        
        try {
            const res = await fetch(`${this.BASE_URL}/api/voting/positions.php?election_id=${encodeURIComponent(electionId)}`, { 
                credentials: 'same-origin' 
            });
            const data = await res.json();
            const arr = this.toArray(data, 'positions');
            for (const p of arr) {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = p.name + (p.category ? ` (${p.category})` : '');
                opt.dataset.category = p.category || '';
                this.positionSelect.appendChild(opt);
            }
        } catch (e) { 
            console.error('Failed to load positions:', e);
            this.showError('Failed to load positions. Please select a different election.');
        }
    },
    
    showError: function(message) {
        if (this.errorDiv) {
            this.errorDiv.querySelector('span').textContent = message;
            this.errorDiv.classList.remove('hidden');
            setTimeout(() => {
                this.errorDiv.classList.add('hidden');
            }, 5000);
        } else {
            Toast.error(message);
        }
    },
    
    showSuccess: function(message) {
        if (this.successDiv) {
            this.successDiv.querySelector('span').textContent = message;
            this.successDiv.classList.remove('hidden');
            setTimeout(() => {
                this.successDiv.classList.add('hidden');
            }, 3000);
        } else {
            Toast.success(message);
        }
    },
    
    resetForm: function() {
        this.form.reset();
        document.getElementById('candidate-id').value = '';
        this.modalTitle.textContent = 'Add New Candidate';
        this.photoPreview.classList.add('hidden');
        this.photoPlaceholder.classList.remove('hidden');
        this.electionSelect.value = '';
        this.positionSelect.innerHTML = '<option value="">Select Position</option>';
        
        // Remove any photo note
        const photoNote = document.getElementById('current-photo-note');
        if (photoNote) photoNote.remove();
    },
    
    // New method for editing
    editCandidate: function(candidate) {
        if (window.ActivityLogger) {
            ActivityLogger.log('edit_click', 'Clicked edit candidate', {
                candidate_id: candidate.id,
                candidate_name: candidate.first_name + ' ' + candidate.last_name
            });
        }
        
        // First, ensure elections are loaded
        this.loadElections().then(() => {
            // Reset form first
            this.resetForm();
            
            // Set candidate ID
            const candidateIdField = document.getElementById('candidate-id');
            if (candidateIdField) {
                candidateIdField.value = candidate.id || '';
            }
            
            // Set user select
            if (this.userSelect) {
                this.userSelect.value = candidate.user_id || '';
            }
            
            // Set manifesto
            const manifestoField = document.getElementById('candidate-description');
            if (manifestoField) {
                manifestoField.value = candidate.manifesto || '';
            }
            
            // Set election
            if (this.electionSelect) {
                this.electionSelect.value = candidate.election_id || '';
                
                // Load positions for this election
                if (typeof this.loadPositions === 'function') {
                    this.loadPositions(candidate.election_id).then(() => {
                        // After positions are loaded, set the position value
                        if (this.positionSelect) {
                            // Small delay to ensure options are populated
                            setTimeout(() => {
                                this.positionSelect.value = candidate.position_id || '';
                            }, 100);
                        }
                    }).catch(error => {
                        console.error('Failed to load positions:', error);
                        // Fallback: try to set position directly
                        if (this.positionSelect) {
                            this.positionSelect.value = candidate.position_id || '';
                        }
                    });
                }
            }
            
            // Update modal title
            if (this.modalTitle) {
                this.modalTitle.textContent = 'Edit Candidate';
            }
            
            // Show current photo if exists
            if (candidate.photo_path && this.photoPreview && this.photoPlaceholder) {
                this.photoPreview.src = this.BASE_URL + '/' + candidate.photo_path;
                this.photoPreview.classList.remove('hidden');
                this.photoPlaceholder.classList.add('hidden');
                
                // Add a note about current photo
                const photoNote = document.createElement('p');
                photoNote.className = 'text-xs text-gray-500 mt-1';
                photoNote.id = 'current-photo-note';
                photoNote.innerHTML = 'Current photo shown. Upload new to replace.';
                
                // Remove any existing note
                const existingNote = document.getElementById('current-photo-note');
                if (existingNote) existingNote.remove();
                
                if (this.photoPreviewContainer) {
                    this.photoPreviewContainer.appendChild(photoNote);
                }
            }
            
            // Show the modal
            showModal('add-candidate-modal');
        }).catch(error => {
            console.error('Failed to load elections:', error);
            Toast.error('Failed to load election data');
        });
    },
    
    handleSubmit: async function(e) {
        e.preventDefault();
        
        const isEdit = !!document.getElementById('candidate-id').value;
        const candidateId = document.getElementById('candidate-id').value;
        
        // Validate required fields
        if (!this.userSelect.value) {
            this.showError('Please select a user');
            return;
        }
        if (!this.electionSelect.value) {
            this.showError('Please select an election');
            return;
        }
        if (!this.positionSelect.value) {
            this.showError('Please select a position');
            return;
        }
        
        // Check for duplicates first - IMPORTANT: Exclude current ID when editing
        const duplicateCheckUrl = `${this.BASE_URL}/api/admin/check-duplicate.php?user_id=${encodeURIComponent(this.userSelect.value)}&election_id=${encodeURIComponent(this.electionSelect.value)}&position_id=${encodeURIComponent(this.positionSelect.value)}${isEdit ? `&exclude_id=${encodeURIComponent(candidateId)}` : ''}`;
        
        try {
            const duplicateResponse = await fetch(duplicateCheckUrl);
            const duplicateData = await duplicateResponse.json();
            
            if (duplicateData.exists) {
                this.showError(duplicateData.message || 'This user is already a candidate for this position in this election');
                return;
            }
        } catch (error) {
            console.error('Error checking duplicate:', error);
            // Continue anyway, let the server handle it
        }
        
        // Log attempt
        if (window.ActivityLogger) {
            ActivityLogger.log(
                isEdit ? 'update_attempt' : 'create_attempt',
                isEdit ? 'Attempting to update candidate' : 'Attempting to create candidate',
                {
                    user_id: this.userSelect.value,
                    election_id: this.electionSelect.value,
                    position_id: this.positionSelect.value
                }
            );
        }
        
        // Show loading state
        this.saveBtn.disabled = true;
        this.saveBtn.classList.add('btn-loading');
        const originalBtnText = this.saveBtn.innerHTML;
        this.saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving...';
        
        const formData = new FormData();
        formData.append('user_id', this.userSelect.value);
        formData.append('election_id', this.electionSelect.value);
        formData.append('position_id', this.positionSelect.value);
        formData.append('manifesto', document.getElementById('candidate-description').value);
        formData.append('csrf_token', this.CSRF_TOKEN);
        
        if (isEdit) {
            formData.append('id', candidateId);
            formData.append('_method', 'PUT'); // Method override
        }
        
        if (this.photoInput && this.photoInput.files.length > 0) {
            formData.append('photo', this.photoInput.files[0]);
        }
        
        const url = `${this.BASE_URL}/api/admin/candidates.php${isEdit ? `?id=${encodeURIComponent(candidateId)}` : ''}`;
        
        try {
            const response = await fetch(url, {
                method: 'POST', // Always POST, but with _method override
                headers: {
                    'X-CSRF-Token': this.CSRF_TOKEN
                },
                body: formData
            });
            
            const responseText = await response.text();
            let data;
            
            try {
                data = JSON.parse(responseText);
            } catch (e) {
                console.error('Failed to parse JSON:', responseText);
                if (responseText.includes('<br') || responseText.includes('<b>')) {
                    const errorMatch = responseText.match(/<b>(.*?)<\/b>/);
                    const errorMsg = errorMatch ? errorMatch[1] : 'PHP Error occurred';
                    throw new Error(`Server error: ${errorMsg}. Check server logs.`);
                } else {
                    throw new Error('Server returned invalid response. Please try again.');
                }
            }
            
            if (data.success) {
                if (window.ActivityLogger) {
                    ActivityLogger.log(
                        isEdit ? 'update_success' : 'create_success',
                        isEdit ? 'Candidate updated successfully' : 'Candidate created successfully',
                        {
                            candidate_id: candidateId || data.candidate_id
                        }
                    );
                }
                
                this.showSuccess(isEdit ? 'Candidate updated successfully!' : 'Candidate created successfully!');
                
                setTimeout(() => {
                    hideModal('add-candidate-modal');
                    window.location.reload();
                }, 1500);
            } else {
                throw new Error(data.message || 'Failed to save candidate');
            }
        } catch (error) {
            console.error('Save error:', error);
            
            if (window.ActivityLogger) {
                ActivityLogger.log(
                    isEdit ? 'update_error' : 'create_error',
                    'Error saving candidate',
                    { error: error.message }
                );
            }
            
            this.showError(error.message);
        } finally {
            // Restore button state
            this.saveBtn.disabled = false;
            this.saveBtn.classList.remove('btn-loading');
            this.saveBtn.innerHTML = originalBtnText;
        }
    }
};

// Initialize on DOM load
document.addEventListener('DOMContentLoaded', function() {
    window.candidateModalFunctions.init();
});

// Global reset function
window.resetCandidateForm = function() {
    window.candidateModalFunctions.resetForm();
};

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('add-candidate-modal');
        if (modal && !modal.classList.contains('hidden')) {
            if (window.ActivityLogger) {
                ActivityLogger.log('modal_closed_escape', 'Modal closed with Escape key');
            }
            hideModal('add-candidate-modal');
        }
    }
});

// Close modal when clicking outside
document.addEventListener('click', function(e) {
    const modal = document.getElementById('add-candidate-modal');
    if (modal && !modal.classList.contains('hidden') && e.target === modal) {
        if (window.ActivityLogger) {
            ActivityLogger.log('modal_closed_outside', 'Modal closed by clicking outside');
        }
        hideModal('add-candidate-modal');
    }
});
</script>