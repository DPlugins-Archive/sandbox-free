document.addEventListener("DOMContentLoaded", function(){
    document.getElementById('sandbox-publish-changes').addEventListener('click', function(event) {
        event.preventDefault();
        
        if (confirm('🔥 PUBLISH the sandbox draft?')) {
            window.location = this.href;        
        }
    });

    document.getElementById('sandbox-delete-changes').addEventListener('click', function(event) {
        event.preventDefault();
        
        if (confirm('DELETE the sandbox draft?')) {
            window.location = this.href;        
        }
    });
});
  