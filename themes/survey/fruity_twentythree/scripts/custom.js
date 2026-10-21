/******************
    User custom JS
    ---------------

   Put JS-functions for your template here.
   If possible use a closure, or add them to the general Template Object "Template"
*/

/*
 * AIR-Tools: upstream fix #16684 (commit 038ed60ee8) lives in survey.js, which this theme does not load.
 * Same guard here: after the first Next/Previous/Submit, disable the buttons so a second click
 * cannot send a request that ends in "session expired". Remove once upstream fixes this theme.
 */
$(document).on('submit', function (e) {
    if (e.target.id != 'limesurvey' || e.isDefaultPrevented()) {
        return;
    }
    var submitter = e.originalEvent ? $(e.originalEvent.submitter) : null;
    if (!submitter || ['movenext', 'moveprev', 'movesubmit'].indexOf(submitter.attr('value')) === -1) {
        return;
    }
    $('#limesurvey button[type="submit"]:not(:disabled)').prop('disabled', true).addClass('ls-disabled-on-submit');
    // A disabled button is left out of the request, so send its value as a hidden input.
    $('#onsubmitbuttoninput').remove();
    $('#limesurvey').append('<input id="onsubmitbuttoninput" name=\'' + submitter.attr('name') + '\' value=\'' + submitter.attr('value') + '\' type=\'hidden\' />');
});

// Re-enable the buttons when the browser restores this page from its back/forward cache.
$(window).on('pageshow', function (e) {
    if (!e.originalEvent.persisted) {
        return;
    }
    $('#limesurvey .ls-disabled-on-submit').prop('disabled', false).removeClass('ls-disabled-on-submit');
    $('#onsubmitbuttoninput').remove();
});
