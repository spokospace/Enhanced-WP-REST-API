document.addEventListener('DOMContentLoaded', function () {
    const headlineTextarea = document.getElementById('formatted_headline');
    const preview = document.getElementById('formatted_headline_preview');
    const insertTitleButton = document.getElementById('insert-title');


    // Function to wrap the selected text with HTML tags
    function wrapSelection(tag) {
        const selectedText = headlineTextarea.value.substring(headlineTextarea.selectionStart, headlineTextarea.selectionEnd);
        const before = headlineTextarea.value.substring(0, headlineTextarea.selectionStart);
        const after = headlineTextarea.value.substring(headlineTextarea.selectionEnd);

        // If text is selected, wrap it with the HTML tag
        let newText = '';
        if (selectedText) {
            newText = before + `<${tag}>` + selectedText + `</${tag}>` + after;
        } else {
            // If no text is selected, insert the tag at the cursor position
            newText = before + `<${tag}></${tag}>` + after;
        }

        // Set the new value to the textarea
        headlineTextarea.value = newText;

        // Adjust cursor position after inserting the tag
        const cursorPos = before.length + `<${tag}>`.length + selectedText.length;
        headlineTextarea.setSelectionRange(cursorPos, cursorPos);

        // Refresh the preview (innerHTML of the preview container)
        if (preview) {
            preview.innerHTML = headlineTextarea.value;
        }
    }

    // Button for inserting <b> tag
    const addBoldButton = document.getElementById('add-bold');
    if (addBoldButton) {
        addBoldButton.addEventListener('click', function () {
            wrapSelection('b');
        });
    }

    // Button for inserting <small> tag
    const addSmallButton = document.getElementById('add-small');
    if (addSmallButton) {
        addSmallButton.addEventListener('click', function () {
            wrapSelection('small');
        });
    }

    // Update the preview in real time as the user types
    if (headlineTextarea) {
        headlineTextarea.addEventListener('input', function () {
            const textareaValue = headlineTextarea.value;

            // Dynamically update the HTML preview
            if (preview) {
                preview.innerHTML = textareaValue;
            }
        });
    }

    function getPostTitle() {
        if (typeof wp !== 'undefined' && wp.data) {
            return wp.data.select("core/editor").getEditedPostAttribute("title") || '';
        }
        return '';
    }


    if (insertTitleButton && headlineTextarea) {
        insertTitleButton.addEventListener('click', function () {
            const postTitle = getPostTitle();
            if (postTitle) {
                headlineTextarea.value = postTitle;
                preview.innerHTML = postTitle;
            }
        });
    }
});
