(function(){
  'use strict';

  var tim = null;
  var now = new Date().getTime();
  var timer = document.getElementById('timer');
  var deadline = now + wc_wosa_duration * 1000;

  function countDown() {
    var current = new Date().getTime();
    var dist = deadline - current;
    var minutes = Math.floor((dist % (1000 * 60 * 60)) / (1000 * 60));
    var seconds = Math.floor((dist % (1000 * 60)) / 1000);

    if ( dist < 1000 ) {
      clearTimeout( tim );
      window.location.reload(false);
    }

    var out = [
      minutes < 10 ? '0' + minutes : minutes,
      seconds < 10 ? '0' + seconds : seconds
    ];

    timer.innerHTML = out.join(':');
  }

  countDown();
  tim = setInterval(function() {
    countDown();
  }, 500);

})();
