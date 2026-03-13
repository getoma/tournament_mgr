/***
 * recover page scroll position after form submit
 */
document.addEventListener("DOMContentLoaded", () => {

   const forms = document.querySelectorAll("form");

   forms.forEach(form => {
      form.addEventListener("submit", () => {
         sessionStorage.setItem("scrollY", window.scrollY);
      });
   });

   const scrollY = sessionStorage.getItem("scrollY");

   if (scrollY) {
      window.scrollTo(0, parseInt(scrollY));
      sessionStorage.removeItem("scrollY");
   }

});
