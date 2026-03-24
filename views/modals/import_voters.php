<div id="import-voters-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 modal-overlay">
    <div class="bg-white rounded-lg shadow-xl p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-pink-900">Import Voters</h3>
            <button onclick="if(window.votersModule) window.votersModule.hideModal('import-voters-modal')" class="text-gray-400 hover:text-gray-500 modal-close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="bg-blue-50 p-4 rounded-lg border-l-4 border-blue-500 mb-4">
    <p class="text-sm text-blue-800 font-medium mb-2">CSV Format Instructions (AUTO-ID SUPPORT):</p>
    <p class="text-xs text-blue-700 mb-2">Your CSV file should have the following columns in order:</p>
    <code class="text-xs bg-white p-2 rounded block border border-blue-200 mb-2">
        ID (optional), First Name, Last Name, Department, Level, Email, Entry Year, Graduation Year (optional)
    </code>
    <div class="text-xs text-blue-700 space-y-1">
        <p><strong>ID column:</strong> Leave empty or use '*' for auto-generation, or provide custom ID (must be 3 letters + 6 digits)</p>
        <p><strong>Department:</strong> General Science, General Arts, Business, Technical, Home Economics, Visual Arts, Agriculture, Vocational</p>
        <p><strong>Level format examples:</strong> 1A1, 2B2, 3C1, 4D3, 1AG1, 1AG2, 2AG1, 2AG2</p>
        <p><strong>Entry Year:</strong> Required. Format: YYYY (e.g., 2023, 2024, 2025)</p>
        <p><strong>Graduation Year:</strong> Optional. Format: YYYY (e.g., 2026, 2027)</p>
        <p class="mt-2"><strong>Sample CSV rows:</strong><br>
        <code class="text-xs bg-white p-1 rounded">*,John,Doe,General Science,1A1,john.doe@school.edu,2025,2028</code><br>
        <code class="text-xs bg-white p-1 rounded">,Jane,Smith,General Arts,2B2,jane.smith@school.edu,2024,2027</code><br>
        <code class="text-xs bg-white p-1 rounded">GSC240001,Bob,Johnson,General Science,3C1,bob.j@school.edu,2024,2027</code></p>
    </div>
</div>

            <div class="bg-yellow-50 p-3 rounded-lg border-l-4 border-yellow-400">
                <p class="text-xs text-yellow-800">
                    <i class="fas fa-info-circle mr-1"></i>
                    <strong>Note:</strong> If no graduation year is provided, it will be left empty. 
                    You can set graduation years later in the edit form.
                </p>
            </div>
            
            <div id="drop-area" class="mt-4 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md hover:border-pink-500 transition-colors">
                <div class="space-y-1 text-center">
                    <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48" aria-hidden="true">
                        <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <div class="flex text-sm text-gray-600 justify-center">
                        <label for="voter-file" class="relative cursor-pointer bg-white 
                        rounded-md font-medium text-pink-900 hover:text-pink-800 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-pink-500">
                            <span>Upload a file</span>
                            <input id="voter-file" name="voter-file" type="file" class="sr-only" accept=".csv">
                        </label>
                        <p class="pl-1">or drag and drop</p>
                    </div>
                    <p class="text-xs text-gray-500">
                        CSV file up to 10MB
                    </p>
                    <p id="csv-filename" class="text-xs font-medium text-green-600 mt-2"></p>
                </div>
            </div>

            <!-- Preview Section -->
            <div id="import-preview" class="hidden mt-4">
                <h4 class="text-sm font-medium text-gray-700 mb-2">File Preview (first 3 rows):</h4>
                <div class="bg-gray-50 rounded-lg p-3 overflow-x-auto">
                    <pre id="preview-content" class="text-xs text-gray-600"></pre>
                </div>
            </div>
        </div>
        
        <div class="flex justify-end space-x-3 pt-6 border-t border-gray-200 mt-4">
            <button type="button" onclick="if(window.votersModule) window.votersModule.hideModal('import-voters-modal')" 
                    class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                Cancel
            </button>
            <button type="button" id="import-submit-btn" disabled
                    class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium 
                    text-white bg-pink-900 hover:bg-pink-800 disabled:opacity-50 disabled:cursor-not-allowed flex items-center transition-colors">
                <span id="import-btn-text">Import Voters</span>
                <span id="import-loading" class="hidden">
                    <span class="spinner mr-2"></span> Importing...
                </span>
            </button>
        </div>
    </div>
</div>

<script>
// Add some basic styles for the spinner (if not already added)
if (!document.getElementById('import-spinner-style')) {
    const style = document.createElement('style');
    style.id = 'import-spinner-style';
    style.textContent = `
    .spinner {
        border: 2px solid #f3f3f3;
        border-top: 2px solid #831843;
        border-radius: 50%;
        width: 16px;
        height: 16px;
        animation: spin 1s linear infinite;
        display: inline-block;
        margin-right: 8px;
    }
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    .drag-over {
        background-color: #f9fafb;
        border-color: #831843 !important;
    }
    `;
    document.head.appendChild(style);
}

// File input handling
document.addEventListener('DOMContentLoaded', function() {
    const fileInput = document.getElementById('voter-file');
    const fileName = document.getElementById('csv-filename');
    const dropArea = document.getElementById('drop-area');
    const importBtn = document.getElementById('import-submit-btn');
    const previewDiv = document.getElementById('import-preview');
    const previewContent = document.getElementById('preview-content');
    
    if (fileInput && fileName) {
        fileInput.addEventListener('change', function() {
            const file = this.files[0];
            fileName.textContent = file?.name || 'No file chosen';
            if (importBtn) importBtn.disabled = !file;
            
            // Show preview if file is selected
            if (file) {
                previewFile(file);
            } else {
                previewDiv.classList.add('hidden');
            }
        });
    }
    
    // Function to preview CSV file
    function previewFile(file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const lines = e.target.result.split('\n').slice(0, 3); // First 3 lines
            previewContent.textContent = lines.join('\n');
            previewDiv.classList.remove('hidden');
        };
        reader.readAsText(file);
    }
    
    // Drag and drop handling
    if (dropArea) {
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropArea.addEventListener(eventName, preventDefaults, false);
        });
        
        ['dragenter', 'dragover'].forEach(eventName => {
            dropArea.addEventListener(eventName, highlight, false);
        });
        
        ['dragleave', 'drop'].forEach(eventName => {
            dropArea.addEventListener(eventName, unhighlight, false);
        });
        
        dropArea.addEventListener('drop', handleDrop, false);
    }
    
    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }
    
    function highlight() {
        dropArea.classList.add('drag-over');
    }
    
    function unhighlight() {
        dropArea.classList.remove('drag-over');
    }
    
    function handleDrop(e) {
        const dt = e.dataTransfer;
        const files = dt.files;
        const fileInput = document.getElementById('voter-file');
        if (fileInput) {
            fileInput.files = files;
            const fileName = files[0]?.name || '';
            document.getElementById('csv-filename').textContent = fileName;
            if (importBtn) importBtn.disabled = !fileName;
            
            // Show preview
            if (files[0]) {
                previewFile(files[0]);
            }
        }
    }
    
    // Import button click handler
    if (importBtn) {
        importBtn.addEventListener('click', async function() {
            const fileInput = document.getElementById('voter-file');
            if (!fileInput || !fileInput.files[0]) {
                if (window.votersModule) {
                    window.votersModule.showToast('Please select a CSV file', 'error');
                } else {
                    alert('Please select a CSV file');
                }
                return;
            }
            
            // Show loading state
            document.getElementById('import-btn-text').classList.add('hidden');
            document.getElementById('import-loading').classList.remove('hidden');
            this.disabled = true;
            
            const fd = new FormData();
            fd.append('voter_file', fileInput.files[0]);
            fd.append('csrf_token', '<?= $_SESSION['csrf_token'] ?? '' ?>');

            try {
                const res = await fetch('<?= BASE_URL ?>/api/admin/import_voters.php', {
                    method: 'POST',
                    body: fd
                });
                
                const contentType = res.headers.get('content-type');
                let data;
                
                if (contentType && contentType.includes('application/json')) {
                    data = await res.json();
                } else {
                    const text = await res.text();
                    console.error('Non-JSON response:', text);
                    throw new Error('Server returned an invalid response. Please check server logs.');
                }
                
                if (data.success) {
                    let message = `✅ Imported: ${data.inserted} | ⏭️ Skipped: ${data.skipped}`;
                    if (data.graduation_years_set) {
                        message += `\n🎓 Graduation years set: ${data.graduation_years_set}`;
                    }
                    if (data.failed && data.failed.length > 0) {
                        message += '\n\n❌ Failed entries:\n' + data.failed.slice(0, 5).join('\n');
                        if (data.failed.length > 5) {
                            message += `\n...and ${data.failed.length - 5} more`;
                        }
                    }
                    
                    if (window.votersModule) {
                        window.votersModule.showToast(message, data.inserted > 0 ? 'success' : 'warning');
                        window.votersModule.hideModal('import-voters-modal');
                    }
                    setTimeout(() => location.reload(), 2000);
                } else {
                    throw new Error(data.message || 'Import failed');
                }
            } catch (err) {
                console.error('Import error:', err);
                if (window.votersModule) {
                    window.votersModule.showToast(err.message, 'error');
                } else {
                    alert(err.message);
                }
            } finally {
                // Reset loading state
                document.getElementById('import-btn-text').classList.remove('hidden');
                document.getElementById('import-loading').classList.add('hidden');
                this.disabled = false;
            }
        });
    }
});
</script>