function updateClock() {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const seconds = String(now.getSeconds()).padStart(2, '0');

    const formattedTime = `${year} ${month} ${day} ${hours}:${minutes}:${seconds}`;
    document.getElementById('clock').textContent = formattedTime;
}

setInterval(updateClock, 1000);
updateClock();

// NEW Clock and Date function



// function updateClock() {
//     const now = new Date();

//     // Date formatting
//     const year = now.getFullYear();
//     const month = String(now.getMonth() + 1).padStart(2, '0');
//     const day = String(now.getDate()).padStart(2, '0');

//     const months = ['Jan', 'Feb', 'Már', 'Ápr', 'Máj', 'Jún', 'Júl', 'Aug', 'Szep', 'Okt', 'Nov', 'Dec'];
//     const days = ['Vasárnap', 'Hétfő', 'Kedd', 'Szerda', 'Csütörtök', 'Péntek', 'Szombat'];

//     const dayName = days[now.getDay()];
//     const monthName = months[now.getMonth()];

//     // Time formatting
//     const hours = String(now.getHours()).padStart(2, '0');
//     const minutes = String(now.getMinutes()).padStart(2, '0');
//     const seconds = String(now.getSeconds()).padStart(2, '0');

//     document.getElementById('date').textContent = `${year}. ${monthName} ${day}. - ${dayName}`;
//     document.getElementById('clock').innerHTML = `<i class="bi bi-clock me-2"></i>${hours}:${minutes}:${seconds}`;
// }

// // Update clock every second
// setInterval(updateClock, 1000);
// updateClock(); // Initial call