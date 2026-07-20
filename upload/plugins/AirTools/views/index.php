<style>
    #in_survey_common_action {
        padding: 0;
        height: 100%;
        overflow: auto;
    }
</style>

<script>
// Remove the footer element
document.addEventListener('DOMContentLoaded', function() {
    // remove tag footer
    const footer = document.querySelector('footer');
    footer.parentNode.removeChild(footer);
});
</script>

<div id='analytics-widget' style='overflow: auto;'></div>
<div id='library-widget' style='overflow: auto;'></div>
<div id='document-to-conditions-widget' style='overflow: auto;'></div>