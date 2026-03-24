<?php
// views/modals/add_voter.php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
?>
<div id="add-voter-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 modal-overlay">
  <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-6 relative max-h-[90vh] overflow-y-auto">
    <div class="flex justify-between items-center mb-4">
      <h2 id="form-title" class="text-xl font-semibold text-gray-900">Add New Voter</h2>
      <button type="button" onclick="if(window.votersModule) window.votersModule.hideModal('add-voter-modal')" class="text-gray-500 hover:text-gray-700 modal-close">
        <i class="fas fa-times"></i>
      </button>
    </div>

    <!-- Auto-Generated ID Preview -->
    <div id="id-preview-container" class="mb-4 p-3 bg-blue-50 rounded-lg border border-blue-200 hidden">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-xs text-blue-700 font-medium">AUTO-GENERATED ID:</p>
          <p id="preview-id" class="text-lg font-mono font-bold text-blue-900">GSC250001</p>
          <p class="text-xs text-blue-600 mt-1">Format: [DEPT][YEAR][4-DIGIT]</p>
        </div>
        <i class="fas fa-magic text-blue-400 text-2xl"></i>
      </div>
    </div>

    <!-- Auto-Generated Password Display (shown after submission) -->
    <div id="password-display" class="hidden mb-4 p-4 bg-green-50 border border-green-200 rounded-lg">
      <div class="flex items-start">
        <div class="flex-shrink-0">
          <i class="fas fa-key text-green-600 text-xl"></i>
        </div>
        <div class="ml-3 flex-1">
          <h4 class="text-sm font-medium text-green-800">🔐 Auto-Generated Password</h4>
          <div class="mt-2">
            <p class="text-sm text-green-700">Please save this password. It will not be shown again!</p>
            <div class="mt-2 flex items-center bg-white p-2 rounded border border-green-300">
              <code id="generated-password" class="font-mono text-lg text-green-800 flex-1"></code>
              <button type="button" onclick="copyGeneratedPassword()" class="ml-2 text-green-600 hover:text-green-800" title="Copy to clipboard">
                <i class="fas fa-copy"></i>
              </button>
            </div>
            <p class="text-xs text-green-600 mt-2">
              <i class="fas fa-info-circle"></i> 
              8-character password: uppercase + lowercase + numbers + special
            </p>
          </div>
        </div>
        <button type="button" onclick="document.getElementById('password-display').classList.add('hidden')" class="text-green-600 hover:text-green-800">
          <i class="fas fa-times"></i>
        </button>
      </div>
    </div>

    <form id="voter-form" class="space-y-4" autocomplete="off">
      <input type="hidden" id="is-edit" name="is_edit" value="false">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      
      <!-- Hidden ID field for edit mode only -->
      <input type="hidden" id="voter-id" name="id" value="">

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            First Name <span class="text-red-500">*</span>
          </label>
          <input id="voter-firstname" name="first_name" type="text" 
                 class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-pink-500 focus:border-pink-500" 
                 required>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Last Name <span class="text-red-500">*</span>
          </label>
          <input id="voter-lastname" name="last_name" type="text" 
                 class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-pink-500 focus:border-pink-500" 
                 required>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Department <span class="text-red-500">*</span>
          </label>
          <select id="voter-department" name="department" 
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-pink-500 focus:border-pink-500" 
                  required onchange="window.votersModule?.previewGeneratedId()">
            <option value="">--Select Department--</option>
            <option value="General Science">General Science (GSC)</option>
            <option value="General Arts">General Arts (GAR)</option>
            <option value="Agriculture">Agric Dept (AGR)</option>
            <option value="Business">Business Dept (BUS)</option>
            <option value="Technical">Technical Dept (TEC)</option>
            <option value="Home Economics">Home Economics (HEC)</option>
            <option value="Visual Arts">Visual Art (VAR)</option>
            <option value="Vocational">Vocational (VOC)</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Entry Year <span class="text-red-500">*</span>
          </label>
          <select id="voter-entry-year" name="entry_year" 
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-pink-500 focus:border-pink-500" 
                  required onchange="window.votersModule?.previewGeneratedId()">
            <option value="">--Entry Year--</option>
            <?php for($year = date('Y'); $year >= 2020; $year--): ?>
              <option value="<?= $year ?>" <?= $year == date('Y') ? 'selected' : '' ?>>
                <?= $year ?> (Class of <?= $year + 3 ?>)
              </option>
            <?php endfor; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Level & Class <span class="text-red-500">*</span>
          </label>
          <select id="voter-level" name="level" 
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-pink-500 focus:border-pink-500" 
                  required>
            <option value="">--Select Level & Class--</option>
            <optgroup label="Form 1">
                <option value="1A1">1A1</option>
                <option value="1A2">1A2</option>
                <option value="1A3">1A3</option>
                <option value="1A4">1A4</option>
                <option value="1A5">1A5</option>
                <option value="1A6">1A6</option>
                <option value="1A7">1A7</option>
                <option value="1A8">1A8</option>
                <option value="1A9">1A9</option>
                <option value="1A0">1A0</option>
                <option value="1B1">1B1</option>
                <option value="1B2">1B2</option>
                <option value="1C1">1C1</option>
                <option value="1C2">1C2</option>
                <option value="1E1">1E1</option>
                <option value="1E2">1E2</option>
                <option value="1E3">1E3</option>
                <option value="1E4">1E4</option>
                <option value="1AG1">1AG1</option>
                <option value="1AG2">1AG2</option>
                <option value="1DH1">1DH1</option>
                <option value="1DH2">1DH2</option>
                <option value="1DH3">1DH3</option>
                <option value="1DH4">1DH4</option>
                <option value="1DV1">1DV1</option>
                <option value="1DV2">1DV2</option>
            </optgroup>
            <optgroup label="Form 2">
                <option value="2A1">2A1</option>
                <option value="2A2">2A2</option>
                <option value="2A3">2A3</option>
                <option value="2A4">2A4</option>
                <option value="2A5">2A5</option>
                <option value="2A6">2A6</option>
                <option value="2A7">2A7</option>
                <option value="2A8">2A8</option>
                <option value="2A9">2A9</option>
                <option value="2A0">2A0</option>
                <option value="2B1">2B1</option>
                <option value="2B1">2B2</option>
                <option value="2C1">2C1</option>
                <option value="2C2">2C2</option>
                <option value="2E1">2E1</option>
                <option value="2E2">2E2</option>
                <option value="2E3">2E3</option>
                <option value="2E4">2E4</option>
                <option value="2AG1">2AG1</option>
                <option value="2AG2">2AG2</option>
                <option value="2DH1">2DH1</option>
                <option value="2DH2">2DH2</option>
                <option value="2DH3">2DH3</option>
                <option value="2DH4">2DH4</option>
                <option value="2DV1">2DV1</option>
                <option value="2DV2">2DV2</option>
            </optgroup>
            <optgroup label="Form 3">
                <option value="3A1">3A1</option>
                <option value="3A2">3A2</option>
                <option value="3A3">3A3</option>
                <option value="3A4">3A4</option>
                <option value="3A5">3A5</option>
                <option value="3A6">3A6</option>
                <option value="3A7">3A7</option>
                <option value="3A8">3A8</option>
                <option value="3A9">3A9</option>
                <option value="3A0">3A0</option>
                <option value="3B1">3B1</option>
                <option value="3C1">3C1</option>
                <option value="3C2">3C2</option>
                <option value="3E1">3E1</option>
                <option value="3E2">3E2</option>
                <option value="3E3">3E3</option>
                <option value="3E4">3E4</option>
                <option value="3AG1">3AG1</option>
                <option value="3AG2">3AG2</option>
                <option value="3DH1">3DH1</option>
                <option value="3DH2">3DH2</option>
                <option value="3DH3">3DH3</option>
                <option value="3DH4">3DH4</option>
                <option value="3DV1">3DV1</option>
                <option value="3DV2">3DV2</option>
            </optgroup>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Password (Optional)</label>
          <input id="voter-password" name="password" type="password" 
                 class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-pink-500 focus:border-pink-500" 
                 placeholder="Leave blank for auto-generated">
          <p class="text-xs text-gray-500 mt-1">Leave empty for unique 8-char password</p>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">
          Email <span class="text-red-500">*</span>
        </label>
        <input id="voter-email" name="email" type="email" 
               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-pink-500 focus:border-pink-500" 
               required>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Graduation Year</label>
        <input id="voter-graduation-year" name="graduation_year" type="number" 
               min="<?= date('Y') ?>" max="<?= date('Y') + 10 ?>" step="1"
               placeholder="e.g., <?= date('Y') + 3 ?>"
               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-pink-500 focus:border-pink-500">
        <p class="text-xs text-gray-500 mt-1">Optional. Format: YYYY (e.g., 2026, 2027)</p>
      </div>

      <div class="flex justify-end space-x-3 pt-4">
        <button type="button" onclick="if(window.votersModule) window.votersModule.hideModal('add-voter-modal')" 
                class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
          Cancel
        </button>
        <button type="submit" id="submit-btn" 
                class="px-4 py-2 bg-pink-900 hover:bg-pink-800 text-white rounded-md text-sm font-medium">
          Add Voter
        </button>
      </div>
    </form>
  </div>
</div>

<script>
// Function to copy generated password to clipboard
function copyGeneratedPassword() {
    const passwordEl = document.getElementById('generated-password');
    const password = passwordEl.textContent;
    
    navigator.clipboard.writeText(password).then(() => {
        // Show temporary success message
        const btn = event.currentTarget;
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i>';
        setTimeout(() => {
            btn.innerHTML = originalHtml;
        }, 2000);
        
        // Optional: Show toast notification
        if (window.votersModule) {
            window.votersModule.showToast('Password copied to clipboard!');
        }
    }).catch(() => {
        alert('Failed to copy password. Please copy it manually.');
    });
}

// Functions for the add voter modal with auto-ID generation
window.openAddVoterModalFromModule = function() {
  console.log('Opening add voter modal with auto-ID generation');
  const form = document.getElementById('voter-form');
  if (form) {
    form.reset();
    document.getElementById('form-title').textContent = 'Add New Voter';
    document.getElementById('submit-btn').textContent = 'Add Voter';
    document.getElementById('is-edit').value = 'false';
    
    // Hide password display if visible
    document.getElementById('password-display').classList.add('hidden');
    
    // Set default entry year to current year
    const entryYearSelect = document.getElementById('voter-entry-year');
    if (entryYearSelect) {
      const currentYear = new Date().getFullYear().toString();
      for (let option of entryYearSelect.options) {
        if (option.value === currentYear) {
          option.selected = true;
          break;
        }
      }
    }
    
    // Hide ID preview initially, will show when department is selected
    document.getElementById('id-preview-container').classList.add('hidden');
    document.getElementById('preview-id').textContent = '';
  }
  if (window.votersModule) {
    window.votersModule.showModal('add-voter-modal');
    // Trigger preview after modal opens
    setTimeout(() => window.votersModule?.previewGeneratedId(), 100);
  } else {
    console.error('votersModule not found');
    alert('Error: Could not open modal. Please refresh the page.');
  }
};

window.editVoterFromModule = function(editData) {
  console.log('Editing voter:', editData);
  const form = document.getElementById('voter-form');
  if (form) {
    form.reset();
    document.getElementById('form-title').textContent = 'Edit Voter';
    document.getElementById('submit-btn').textContent = 'Update Voter';
    document.getElementById('is-edit').value = 'true';
    
    // Hide password display
    document.getElementById('password-display').classList.add('hidden');
    
    if (editData) {
      // For edit mode, we use the existing ID
      document.getElementById('voter-id').value = editData.id || '';
      document.getElementById('voter-firstname').value = editData.first_name || '';
      document.getElementById('voter-lastname').value = editData.last_name || '';
      document.getElementById('voter-email').value = editData.email || '';
      document.getElementById('voter-department').value = editData.department || '';
      document.getElementById('voter-level').value = editData.level || '';
      
      // Set entry year (extract from ID if not available)
      let entryYear = editData.entry_year;
      if (!entryYear && editData.id && editData.id.length >= 5) {
        // Extract from ID format: GSC230001 -> 20 + '23' = 2023
        const yearCode = editData.id.substring(3, 5);
        entryYear = 2000 + parseInt(yearCode);
      }
      
      const entryYearSelect = document.getElementById('voter-entry-year');
      if (entryYearSelect && entryYear) {
        for (let option of entryYearSelect.options) {
          if (option.value == entryYear) {
            option.selected = true;
            break;
          }
        }
      }
      
      document.getElementById('voter-graduation-year').value = editData.graduation_year || '';
      
      // Hide ID preview in edit mode
      document.getElementById('id-preview-container').classList.add('hidden');
    }
  }
  if (window.votersModule) {
    window.votersModule.showModal('add-voter-modal');
  } else {
    console.error('votersModule not found');
    alert('Error: Could not open modal. Please refresh the page.');
  }
};

// Override the form submission to handle password display
document.addEventListener('DOMContentLoaded', function() {
    const voterForm = document.getElementById('voter-form');
    if (voterForm) {
        // Remove any existing listeners and add our enhanced one
        voterForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');
            
            // Show loading spinner
            document.getElementById('loading-spinner').classList.remove('hidden');
            
            try {
                const response = await fetch('<?= BASE_URL ?>/api/admin/voters.php', {
                    method: 'POST',
                    body: formData
                });
                
                const responseText = await response.text();
                let data;
                try {
                    data = JSON.parse(responseText);
                } catch (e) {
                    console.error('Failed to parse JSON:', responseText);
                    throw new Error('Server returned invalid JSON');
                }
                
                if (data.success) {
                    // Check if this was a new voter with auto-generated password
                    if (data.is_new && data.generated_password) {
                        // Show the generated password
                        document.getElementById('generated-password').textContent = data.generated_password;
                        document.getElementById('password-display').classList.remove('hidden');
                        
                        // Show success message but don't close modal immediately
                        if (window.votersModule) {
                            window.votersModule.showToast('Voter added successfully! Password generated.');
                        }
                        
                        // Clear form for next entry but keep modal open
                        document.getElementById('voter-form').reset();
                        
                        // Reset ID preview
                        document.getElementById('id-preview-container').classList.add('hidden');
                        
                        // Reset entry year to default
                        const entryYearSelect = document.getElementById('voter-entry-year');
                        if (entryYearSelect) {
                            const currentYear = new Date().getFullYear().toString();
                            for (let option of entryYearSelect.options) {
                                if (option.value === currentYear) {
                                    option.selected = true;
                                    break;
                                }
                            }
                        }
                        
                        // Refresh the voters list in background
                        setTimeout(() => location.reload(), 10000); // Reload after 10 seconds
                    } else {
                        // For edits or if no password generated, close modal and reload
                        window.votersModule.hideModal('add-voter-modal');
                        window.votersModule.showToast(data.message || 'Voter saved successfully');
                        setTimeout(() => location.reload(), 1500);
                    }
                } else {
                    window.votersModule.showToast(data.message || 'Failed to save voter', 'error');
                }
            } catch (error) {
                console.error('Save error:', error);
                window.votersModule.showToast(error.message || 'Network error. Please try again.', 'error');
            } finally {
                document.getElementById('loading-spinner').classList.add('hidden');
            }
        });
    }
});
</script>