<?php
// views/modals/export_voters.php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
?>
<div id="export-voters-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 modal-overlay">
  <div class="bg-white rounded-lg shadow-lg w-full max-w-md p-6 relative">
    <div class="flex justify-between items-center mb-4">
      <h2 class="text-xl font-semibold text-gray-900">Export Voters to PDF</h2>
      <button type="button" onclick="window.votersModule.hideModal('export-voters-modal')" class="text-gray-500 hover:text-gray-700 modal-close">
        <i class="fas fa-times"></i>
      </button>
    </div>

    <form id="export-form" method="GET" action="<?= BASE_URL ?>/api/admin/export_voters_pdf.php" target="_blank">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      
      <div class="space-y-4">
        <!-- Department Filter -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Department</label>
          <select name="department" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-pink-500 focus:border-pink-500">
            <option value="">All Departments</option>
            <option value="General Science">General Science (GSC)</option>
            <option value="General Arts">General Arts (GAR)</option>
            <option value="Agriculture">Agriculture (AGR)</option>
            <option value="Business">Business (BUS)</option>
            <option value="Technical">Technical (TEC)</option>
            <option value="Home Economics">Home Economics (HEC)</option>
            <option value="Visual Arts">Visual Arts (VAR)</option>
            <option value="Vocational">Vocational (VOC)</option>
          </select>
        </div>

        <!-- Level Filter -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Level/Class</label>
          <select name="level" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-pink-500 focus:border-pink-500">
            <option value="">All Levels</option>
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

        <!-- Entry Year Filter -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Entry Year</label>
          <select name="entry_year" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-pink-500 focus:border-pink-500">
            <option value="">All Years</option>
            <?php for($year = date('Y'); $year >= 2020; $year--): ?>
              <option value="<?= $year ?>"><?= $year ?> (Class of <?= $year + 3 ?>)</option>
            <?php endfor; ?>
          </select>
        </div>

        <!-- Status Filter -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Student Status</label>
          <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-pink-500 focus:border-pink-500">
            <option value="all">All Students</option>
            <option value="active">Active Only</option>
            <option value="graduated">Graduated Only</option>
          </select>
        </div>

        <!-- Security Notice -->
        <div class="bg-yellow-50 p-3 rounded-lg border-l-4 border-yellow-400">
          <div class="flex">
            <i class="fas fa-shield-alt text-yellow-600 mr-3"></i>
            <div>
              <p class="text-xs text-yellow-700 font-medium">⚠️ Security Notice</p>
              <p class="text-xs text-yellow-600 mt-1">This PDF will contain plain text passwords. Handle with care and delete after distribution.</p>
            </div>
          </div>
        </div>

        <div class="flex justify-end space-x-3 pt-4">
          <button type="button" onclick="window.votersModule.hideModal('export-voters-modal')" 
                  class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
            Cancel
          </button>
          <button type="submit" 
                  class="px-4 py-2 bg-purple-700 hover:bg-purple-800 text-white rounded-md text-sm font-medium">
            <i class="fas fa-file-pdf mr-2"></i> Generate PDF
          </button>
        </div>
      </div>
    </form>
  </div>
</div>