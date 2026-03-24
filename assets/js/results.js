// Results Module
export class Results {
  static async loadElections() {
    try {
      const response = await fetch(`${BASE_URL}/api/voting/elections?status=ended`);
      if (!response.ok) {
        throw new Error('Failed to load past elections');
      }
      
      const elections = await response.json();
      this.renderElectionDropdown(elections);
    } catch (error) {
      console.error('Error loading elections:', error);
      this.showError('Failed to load past elections');
    }
  }
  
  static renderElectionDropdown(elections) {
    const dropdown = document.getElementById('election-select');
    if (!dropdown) return;
    
    dropdown.innerHTML = '<option value="">Select an election</option>';
    
    elections.forEach(election => {
      const option = document.createElement('option');
      option.value = election.id;
      option.textContent = election.title;
      dropdown.appendChild(option);
    });
    
    dropdown.addEventListener('change', (e) => {
      if (e.target.value) {
        this.loadElectionResults(e.target.value);
      }
    });
  }
  
  static async loadElectionResults(electionId) {
    try {
      const response = await fetch(`${BASE_URL}/api/voting/results?election_id=${electionId}`);
      if (!response.ok) {
        throw new Error('Failed to load election results');
      }
      
      const data = await response.json();
      if (!data.success) throw new Error(data.message || 'Failed to load election results');
      this.renderResults(data.results);
    } catch (error) {
      console.error('Error loading results:', error);
      this.showError('Failed to load election results');
    }
  }
  
  static renderResults(results) {
  const container = document.getElementById('results-container');
  if (!container) return;

  container.innerHTML = '';

  results.positions.forEach((position, posIndex) => {
    const positionCard = document.createElement('div');
    positionCard.className = 'card mb-8';
    
    // unique chart ID per position
    const chartId = `chart-position-${posIndex}`;

    positionCard.innerHTML = `
      <h3 class="text-xl font-bold mb-4">${position.name}</h3>
      <div class="space-y-4">
        ${this.renderCandidatesTable(position.candidates)}
      </div>
      <canvas id="${chartId}" height="120"></canvas>
    `;
    
    container.appendChild(positionCard);

    // ✅ Decide chart type
    if (position.candidates.length === 1 && position.candidates[0].is_yes_no_candidate === 1) {
      // Single candidate → Yes/No chart
      const candidate = position.candidates[0];
      new Chart(document.getElementById(chartId), {
        type: 'doughnut',
        data: {
          labels: ['Yes', 'No'],
          datasets: [{
            data: [candidate.yes_votes, candidate.no_votes],
            backgroundColor: ['#22c55e', '#ef4444']
          }]
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
      });
    } else {
      // Multi-candidate → Bar chart
      new Chart(document.getElementById(chartId), {
        type: 'bar',
        data: {
          labels: position.candidates.map(c => c.name),
          datasets: [{
            label: 'Votes',
            data: position.candidates.map(c => c.votes),
            backgroundColor: '#3b82f6'
          }]
        },
        options: {
          responsive: true,
          plugins: { legend: { display: false } },
          scales: { y: { beginAtZero: true } }
        }
      });
    }
  });
}
  
  static renderPositionResults(position) {
    if (position.type === 'yes_no') {
      return `
        <div class="flex items-center justify-between p-4 border border-gray-200 rounded-lg">
          <div>
            <h4 class="font-medium">${position.candidate}</h4>
            <p class="text-sm text-gray-600">Single candidate (Yes/No)</p>
          </div>
          <div class="text-right">
            <p class="font-bold text-green-600">
              Yes: ${position.yes_votes} (${position.yes_pct}%)
            </p>
            <p class="font-bold text-red-600">
              No: ${position.no_votes} (${position.no_pct}%)
            </p>
          </div>
        </div>
      `;
    }
    
    // Default: normal multi-candidate race
    return position.candidates.map(candidate => `
      <div class="flex items-center justify-between p-4 border border-gray-200 rounded-lg">
        <div class="flex items-center space-x-4">
          <img src="${candidate.photo || DEFAULT_USER_IMAGE}" 
               alt="${candidate.name}" 
               class="w-12 h-12 rounded-full object-cover">
          <div>
            <h4 class="font-medium">${candidate.name}</h4>
            <p class="text-sm text-gray-600">${candidate.department}</p>
          </div>
        </div>
        <div class="text-right">
          <p class="font-bold">${candidate.votes} votes</p>
          <p class="text-sm text-gray-600">
            ${candidate.percentage.toFixed(1)}%
          </p>
        </div>
      </div>
    `).join('');
  }
  
  static showError(message) {
    const container = document.getElementById('results-container');
    if (container) {
      container.innerHTML = `
        <div class="alert alert-error">
          <p>${message}</p>
        </div>
      `;
    }
  }
}
