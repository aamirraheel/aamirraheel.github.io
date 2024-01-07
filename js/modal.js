document.addEventListener('DOMContentLoaded', function () {
  var modal = document.getElementById('myModal');
  var open = document.getElementById('open-btn');

  open.addEventListener('click', function () {
    modal.style.display = 'flex';
  });

  window.addEventListener('click', function (event) {
    if (event.target === modal) {
      modal.style.display = 'none';
    }
  });
});
