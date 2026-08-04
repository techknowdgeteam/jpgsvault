<?php
// phpmyadmintemplate.php - Integrated with uploaded JPGs auto-move system
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Query Interface</title>
    <style>
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            display: grid;
            grid-template-columns: 1fr 3fr;
            gap: 20px;
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .sidebar {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .main-content {
            display: flex;
            flex-direction: column;
            gap: 15px;
            min-width: 0;
        }
        h1 {
            font-size: 24px;
            color: #333;
            margin: 0 0 20px;
            text-align: center;
        }
        label {
            font-weight: bold;
            color: #444;
            margin-bottom: 5px;
            display: block;
        }
        select, textarea, button {
            padding: 10px;
            font-size: 14px;
            border: 1px solid #ccc;
            border-radius: 4px;
            width: 100%;
            box-sizing: border-box;
        }
        textarea {
            height: 200px;
            resize: vertical;
            transition: border-color 0.3s ease, box-shadow 0.3s ease, background-color 0.3s ease;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            line-height: 1.6;
            background-color: #fafafa;
            color: #333;
            tab-size: 4;
        }
        textarea:focus {
            outline: none;
            border-color: #28a745;
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.2);
        }
        textarea.tab-active {
            border-color: #28a745;
            border-width: 3px;
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.3);
            background-color: #f0fff0;
        }
        textarea.comment-highlight {
            background-color: #f8f9fa;
            background-image: 
                linear-gradient(#f8f9fa 1.6em, #e9ecef 1.6em);
            background-size: 100% 3.2em;
        }
        button {
            background-color: #007bff;
            color: white;
            border: none;
            cursor: pointer;
            font-weight: bold;
            transition: background-color 0.3s ease, transform 0.1s ease;
        }
        button:hover { background-color: #0056b3; }
        button:active { transform: scale(0.98); }

        .query-result, .column-data {
            margin-top: 15px;
            max-height: 500px;
            overflow: auto;
            border: 1px solid #eee;
            border-radius: 4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background-color: #fff;
            table-layout: fixed;
        }

        th, td {
            border: 1px solid #ddd;
            padding: 10px;
            font-size: 13px;
            vertical-align: top;
            word-wrap: break-word;
            overflow-wrap: break-word;
            word-break: break-all;
            white-space: normal;
        }

        th {
            background-color: #f8f9fa;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        #message {
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
            display: none;
        }
        #message.error { 
            color: #721c24; 
            background-color: #f8d7da; 
            border: 1px solid #f5c6cb; 
            display: block;
        }
        #message.success { 
            color: #155724; 
            background-color: #d4edda; 
            border: 1px solid #c3e6cb; 
            display: block;
        }
        #message.info { 
            color: #0c5460; 
            background-color: #d1ecf1; 
            border: 1px solid #bee5eb; 
            display: block;
        }
        #message.warning { 
            color: #856404; 
            background-color: #fff3cd; 
            border: 1px solid #ffeeba; 
            display: block;
        }

        .keyboard-hint {
            font-size: 12px;
            color: #666;
            padding: 5px 10px;
            background-color: #f8f9fa;
            border-radius: 4px;
            border-left: 3px solid #28a745;
        }

        .keyboard-hint strong {
            background-color: #e9ecef;
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: bold;
        }
        
        .comment-indicator {
            font-size: 11px;
            color: #6c757d;
            padding: 4px 8px;
            background-color: #f1f3f5;
            border-radius: 4px;
            display: inline-block;
            margin-left: 10px;
        }
        
        .comment-indicator.has-comments {
            background-color: #d4edda;
            color: #155724;
        }

        .result-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding: 8px 12px;
            background-color: #f8f9fa;
            border-radius: 4px;
            flex-wrap: wrap;
            gap: 8px;
        }

        .result-header .result-info {
            font-size: 14px;
            color: #495057;
            font-weight: 500;
        }

        .copy-btn {
            padding: 6px 16px;
            font-size: 13px;
            background-color: #28a745;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.3s ease, transform 0.1s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .copy-btn:hover {
            background-color: #218838;
        }

        .copy-btn:active {
            transform: scale(0.95);
        }

        .copy-btn.copied {
            background-color: #17a2b8;
        }

        .copy-btn svg {
            width: 16px;
            height: 16px;
            fill: currentColor;
        }

        .reset-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background-color: #dc3545;
            color: white;
            padding: 15px 25px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            font-weight: bold;
            z-index: 9999;
            animation: slideIn 0.3s ease-out;
            display: none;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        /* AUTO-PROCESS STATUS BAR - Always visible but minimal */
        .upload-status-bar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 8px 20px;
            border-radius: 8px;
            margin-bottom: 15px;
            display: none;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            box-shadow: 0 2px 10px rgba(102, 126, 234, 0.3);
            animation: slideDown 0.5s ease-out;
            min-height: 40px;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .upload-status-bar.show {
            display: flex;
        }

        .upload-status-bar .status-icon {
            font-size: 18px;
            margin-right: 8px;
        }

        .upload-status-bar .status-text {
            flex: 1;
            font-size: 13px;
            line-height: 1.4;
        }

        .upload-status-bar .status-text strong {
            font-weight: 700;
        }

        .upload-status-bar .status-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .upload-status-bar .status-actions button {
            padding: 4px 12px;
            font-size: 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s ease;
            width: auto;
            background: rgba(255,255,255,0.2);
            color: white;
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .upload-status-bar .status-actions button:hover {
            background: rgba(255,255,255,0.35);
            transform: scale(1.02);
        }

        .upload-status-bar .status-actions button.primary-btn {
            background: white;
            color: #764ba2;
        }

        .upload-status-bar .status-actions button.primary-btn:hover {
            background: #f0f0f0;
        }

        .upload-status-bar .status-badge {
            background: rgba(255,255,255,0.2);
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            border: 1px solid rgba(255,255,255,0.2);
            backdrop-filter: blur(5px);
        }

        .upload-status-bar .status-badge.warning {
            background: rgba(255, 193, 7, 0.3);
            border-color: #ffc107;
        }

        .upload-status-bar .status-badge.success {
            background: rgba(40, 167, 69, 0.3);
            border-color: #28a745;
        }

        /* Auto-process notification - appears briefly then disappears */
        .auto-process-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 25px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            font-weight: bold;
            z-index: 9999;
            animation: slideIn 0.3s ease-out;
            display: none;
            max-width: 400px;
        }
        .auto-process-notification.success {
            background: #28a745;
            color: white;
        }
        .auto-process-notification.error {
            background: #dc3545;
            color: white;
        }
        .auto-process-notification.info {
            background: #17a2b8;
            color: white;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @media (max-width: 768px) {
            .container { grid-template-columns: 1fr; }
            .result-header { flex-direction: column; gap: 10px; align-items: stretch; }
            .upload-status-bar { flex-direction: column; align-items: stretch; }
            .upload-status-bar .status-actions { justify-content: center; }
        }
    </style>
</head>
<body>
    <h1>Database Query Interface</h1>
    
    <div id="message"></div>
    
    <!-- AUTO-PROCESS STATUS BAR - Shows brief status then auto-processes -->
    <div id="uploadStatusBar" class="upload-status-bar">
        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <span class="status-icon" id="statusIcon">⏳</span>
            <div class="status-text" id="statusText">
                <strong>Checking for uploads...</strong>
            </div>
        </div>
        <div class="status-actions">
            <span class="status-badge" id="statusBadge">⏳</span>
            <button class="primary-btn" id="processUploadedBtn" onclick="processUploadedJpgs()" style="display:none;">▶ Process</button>
        </div>
    </div>
    
    <div class="reset-notification" id="resetNotification">🔄 Reset Complete</div>
    
    <!-- Auto-process notification popup -->
    <div id="autoProcessNotification" class="auto-process-notification"></div>
    
    <div class="container">
        <div class="sidebar">
            <div>
                <label for="table-select">Tables</label>
                <select id="table-select"></select>
            </div>
            <div>
                <label for="column-select">Columns</label>
                <select id="column-select"></select>
            </div>
        </div>
        <div class="main-content">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <label for="sql-query" style="margin: 0;">SQL Query</label>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <span class="comment-indicator" id="comment-indicator">No comments detected</span>
                    <span class="keyboard-hint">
                        <strong>Tab</strong> to focus | <strong>Enter</strong> to execute | <strong>Ctrl+X</strong> to copy via button | <strong>Ctrl+M</strong> to copy directly | <strong>Ctrl+R+Delete</strong> to reset
                    </span>
                </div>
            </div>
            <textarea id="sql-query" placeholder="Enter your SQL query"></textarea>
            <button id="execute-btn" onclick="executeQuery()">Execute Query</button>
            <div id="query-result" class="query-result"></div>
            <div id="column-data" class="column-data"></div>
        </div>
    </div>

    <script>
        let tablePollInterval = null;
        let columnPollInterval = null;
        let uploadStatusPollInterval = null;
        let cachedTables = [];
        let cachedColumns = [];
        let isTabPressed = false;
        let tabTimeout = null;
        let lastQueryResult = null;
        let isPageReady = false;
        let isQueryExecuting = false;
        let isCtrlAPressed = false;
        let hasAutoProcessed = false;

        // Silent clipboard function
        function copyToClipboardSilent(message) {
            try {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(message).catch(() => {
                        fallbackCopySilent(message);
                    });
                } else {
                    fallbackCopySilent(message);
                }
            } catch (e) {
                console.log('Clipboard update:', message);
            }
        }

        function fallbackCopySilent(message) {
            try {
                const textarea = document.createElement('textarea');
                textarea.value = message;
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                textarea.style.left = '-9999px';
                textarea.style.top = '-9999px';
                textarea.style.height = '1px';
                textarea.style.width = '1px';
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
            } catch (e) {
                console.log('Clipboard update (fallback):', message);
            }
        }

        function copyPureDataToClipboard(data) {
            if (!data) return false;
            try {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(data).catch(() => {
                        fallbackCopyPureData(data);
                    });
                } else {
                    fallbackCopyPureData(data);
                }
                return true;
            } catch (e) {
                console.log('Pure data copy failed:', e);
                return false;
            }
        }

        function fallbackCopyPureData(data) {
            try {
                const textarea = document.createElement('textarea');
                textarea.value = data;
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                textarea.style.left = '-9999px';
                textarea.style.top = '-9999px';
                textarea.style.height = '1px';
                textarea.style.width = '1px';
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
            } catch (e) {
                console.log('Pure data fallback copy failed:', e);
            }
        }

        function showMessage(text, type = 'info') {
            const messageDiv = document.getElementById('message');
            messageDiv.textContent = text;
            messageDiv.className = type;
            messageDiv.style.display = 'block';
            if (type === 'info') {
                setTimeout(() => {
                    messageDiv.style.display = 'none';
                }, 3000);
            }
        }

        function showAutoNotification(message, type = 'success') {
            const notification = document.getElementById('autoProcessNotification');
            notification.textContent = message;
            notification.className = 'auto-process-notification ' + type;
            notification.style.display = 'block';
            
            // Auto-hide after 3 seconds
            setTimeout(() => {
                notification.style.display = 'none';
            }, 3000);
        }

        function resetEverything() {
            const textarea = document.getElementById('sql-query');
            textarea.value = '';
            textarea.classList.remove('tab-active');
            textarea.style.borderColor = '';
            textarea.style.boxShadow = '';
            textarea.style.backgroundColor = '';
            
            const resultDiv = document.getElementById('query-result');
            resultDiv.innerHTML = '';
            
            const columnDataDiv = document.getElementById('column-data');
            columnDataDiv.innerHTML = '';
            
            lastQueryResult = null;
            updateCommentIndicator('');
            
            const messageDiv = document.getElementById('message');
            messageDiv.style.display = 'none';
            messageDiv.className = '';
            messageDiv.textContent = '';
            
            try {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText('').catch(() => {});
                }
            } catch (e) {}
            
            isQueryExecuting = false;
            isCtrlAPressed = false;
            showResetNotification();
        }

        function showResetNotification() {
            const notification = document.getElementById('resetNotification');
            notification.style.display = 'block';
            setTimeout(() => {
                notification.style.display = 'none';
            }, 2000);
        }

        // ============================================================
        // UPLOADED JPGS - AUTO PROCESS ON LOAD
        // ============================================================
        
        function checkAndAutoProcessUploadedJpgs() {
            const statusBar = document.getElementById('uploadStatusBar');
            const statusText = document.getElementById('statusDetail') || document.querySelector('#statusText');
            const statusBadge = document.getElementById('statusBadge');
            const statusIcon = document.getElementById('statusIcon');
            const processBtn = document.getElementById('processUploadedBtn');
            
            statusBar.className = 'upload-status-bar show';
            statusIcon.textContent = '⏳';
            statusBadge.textContent = '⏳ Checking';
            
            fetch('uploadedjpgsurl.php?action=status')
                .then(response => response.json())
                .then(data => {
                    if (data.has_data && data.url_count > 0) {
                        // Show status briefly
                        statusIcon.textContent = '📸';
                        statusBadge.textContent = `📦 ${data.url_count} URLs`;
                        statusBadge.className = 'status-badge warning';
                        
                        const detailText = `${data.url_count} URL(s) to move to: <strong>${data.folder}</strong>`;
                        document.querySelector('#statusText').innerHTML = `
                            <strong>📸 Processing uploads...</strong><br>
                            ${detailText}
                        `;
                        
                        // Auto-process immediately
                        if (!hasAutoProcessed) {
                            hasAutoProcessed = true;
                            processUploadedJpgsAuto();
                        }
                    } else {
                        // No data - hide status bar
                        statusBar.className = 'upload-status-bar';
                        document.querySelector('#statusText').innerHTML = `
                            <strong>✅ No pending uploads</strong>
                        `;
                        statusBadge.textContent = '✅';
                        statusBadge.className = 'status-badge success';
                    }
                })
                .catch(error => {
                    console.error('Status check failed:', error);
                    statusBar.className = 'upload-status-bar';
                    document.querySelector('#statusText').innerHTML = `
                        <strong>⚠️ Status check failed</strong>
                    `;
                    statusBadge.textContent = '⚠️';
                    statusBadge.className = 'status-badge warning';
                });
        }

        function processUploadedJpgsAuto() {
            const processBtn = document.getElementById('processUploadedBtn');
            const statusBadge = document.getElementById('statusBadge');
            const statusIcon = document.getElementById('statusIcon');
            
            statusIcon.textContent = '⏳';
            statusBadge.textContent = '⏳ Processing';
            statusBadge.className = 'status-badge warning';
            document.querySelector('#statusText').innerHTML = `<strong>⏳ Processing...</strong>`;
            
            fetch('uploadedjpgsurl.php?action=process', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=process'
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let message = '';
                        let notificationType = 'success';
                        
                        if (data.moved_count > 0) {
                            message = `✅ Moved ${data.moved_count} images to ${data.uploaded_folder}`;
                            if (data.moved_from_folders && data.moved_from_folders.length > 0) {
                                message += ` from ${data.moved_from_folders.join(', ')}`;
                            }
                            statusIcon.textContent = '✅';
                            statusBadge.textContent = `✅ ${data.moved_count} moved`;
                            statusBadge.className = 'status-badge success';
                        } else if (data.urls_not_found && data.urls_not_found.length > 0) {
                            message = `⚠️ ${data.urls_not_found.length} URL(s) not found`;
                            statusIcon.textContent = '⚠️';
                            statusBadge.textContent = `⚠️ ${data.urls_not_found.length} not found`;
                            statusBadge.className = 'status-badge warning';
                            notificationType = 'warning';
                        } else {
                            message = 'ℹ️ No images to move';
                            statusIcon.textContent = 'ℹ️';
                            statusBadge.textContent = 'ℹ️ No data';
                            statusBadge.className = 'status-badge success';
                            notificationType = 'info';
                        }
                        
                        document.querySelector('#statusText').innerHTML = `
                            <strong>${message}</strong>
                        `;
                        
                        // Show notification
                        showAutoNotification(message, notificationType);
                        
                        // Hide status bar after 3 seconds
                        setTimeout(() => {
                            const statusBar = document.getElementById('uploadStatusBar');
                            statusBar.className = 'upload-status-bar';
                        }, 3000);
                        
                    } else {
                        const errorMsg = '❌ ' + (data.message || 'Process failed');
                        statusIcon.textContent = '❌';
                        statusBadge.textContent = '❌ Failed';
                        statusBadge.className = 'status-badge warning';
                        document.querySelector('#statusText').innerHTML = `<strong>${errorMsg}</strong>`;
                        showAutoNotification(errorMsg, 'error');
                        
                        setTimeout(() => {
                            const statusBar = document.getElementById('uploadStatusBar');
                            statusBar.className = 'upload-status-bar';
                        }, 3000);
                    }
                })
                .catch(error => {
                    const errorMsg = '❌ Error: ' + error.message;
                    statusIcon.textContent = '❌';
                    statusBadge.textContent = '❌ Error';
                    statusBadge.className = 'status-badge warning';
                    document.querySelector('#statusText').innerHTML = `<strong>${errorMsg}</strong>`;
                    showAutoNotification(errorMsg, 'error');
                    
                    setTimeout(() => {
                        const statusBar = document.getElementById('uploadStatusBar');
                        statusBar.className = 'upload-status-bar';
                    }, 3000);
                });
        }

        // Manual process button (hidden by default, but available)
        function processUploadedJpgs() {
            processUploadedJpgsAuto();
        }

        // ============================================================
        // END UPLOADED JPGS INTEGRATION
        // ============================================================

        function loadTables() {
            fetch('phpmyadmin_tablesfetch.php')
                .then(response => response.json())
                .then(data => {
                    const tableSelect = document.getElementById('table-select');
                    const currentTable = tableSelect.value;

                    if (data.status !== 'success') {
                        showMessage(data.message, 'error');
                        return;
                    }

                    const newTables = data.tables;
                    if (JSON.stringify(newTables.sort()) !== JSON.stringify(cachedTables.sort())) {
                        cachedTables = newTables;
                        const selectedTable = currentTable && newTables.includes(currentTable) ? currentTable : (newTables[0] || '');
                        
                        tableSelect.innerHTML = '';
                        newTables.forEach(table => {
                            const option = document.createElement('option');
                            option.value = table;
                            option.textContent = table;
                            if (table === selectedTable) option.selected = true;
                            tableSelect.appendChild(option);
                        });

                        if (selectedTable) loadColumns(selectedTable);
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    showMessage('Error loading tables: ' + error.message, 'error');
                });
        }

        function loadColumns(table) {
            if (!table) return;
            fetch(`phpmyadmin_tablesfetch.php?table=${encodeURIComponent(table)}`)
                .then(response => response.json())
                .then(data => {
                    const columnSelect = document.getElementById('column-select');
                    const currentColumn = columnSelect.value;

                    if (data.status !== 'success') return;

                    const newColumns = data.columns.map(col => col.Field);
                    if (JSON.stringify(newColumns.sort()) !== JSON.stringify(cachedColumns.sort())) {
                        cachedColumns = newColumns;
                        const selectedColumn = currentColumn && newColumns.includes(currentColumn) ? currentColumn : (newColumns[0] || '');

                        columnSelect.innerHTML = '';
                        data.columns.forEach(column => {
                            const option = document.createElement('option');
                            option.value = column.Field;
                            option.textContent = `${column.Field} (${column.Type})`;
                            if (column.Field === selectedColumn) option.selected = true;
                            columnSelect.appendChild(option);
                        });
                    }
                });
        }

        function parseSqlQuery(sqlText) {
            if (!sqlText || sqlText.trim() === '') {
                return { cleanQuery: '', comments: [], hasComments: false };
            }

            let cleanQuery = '';
            let comments = [];
            let i = 0;
            let inMultiLineComment = false;
            let inSingleQuote = false;
            let inDoubleQuote = false;
            let inBacktick = false;
            let lineNumber = 1;

            while (i < sqlText.length) {
                const char = sqlText[i];
                const nextChar = sqlText[i + 1] || '';
                
                if (char === '\n') lineNumber++;

                if (!inMultiLineComment && !inSingleQuote && !inDoubleQuote && !inBacktick) {
                    if ((char === '-' && nextChar === '-') || char === '#') {
                        let commentStart = i;
                        let commentEnd = sqlText.indexOf('\n', i);
                        if (commentEnd === -1) commentEnd = sqlText.length;
                        let commentText = sqlText.substring(commentStart, commentEnd).trim();
                        comments.push({ type: 'single-line', text: commentText, line: lineNumber, start: commentStart, end: commentEnd });
                        i = commentEnd;
                        continue;
                    }
                }

                if (!inMultiLineComment && !inSingleQuote && !inDoubleQuote && !inBacktick) {
                    if (char === '/' && nextChar === '*') {
                        inMultiLineComment = true;
                        let commentStart = i;
                        let commentEnd = sqlText.indexOf('*/', i + 2);
                        if (commentEnd === -1) commentEnd = sqlText.length;
                        else commentEnd += 2;
                        let commentText = sqlText.substring(commentStart, commentEnd).trim();
                        comments.push({ type: 'multi-line', text: commentText, line: lineNumber, start: commentStart, end: commentEnd });
                        i = commentEnd;
                        inMultiLineComment = false;
                        continue;
                    }
                }

                if (!inMultiLineComment) {
                    if (char === "'" && !inDoubleQuote && !inBacktick) inSingleQuote = !inSingleQuote;
                    else if (char === '"' && !inSingleQuote && !inBacktick) inDoubleQuote = !inDoubleQuote;
                    else if (char === '`' && !inSingleQuote && !inDoubleQuote) inBacktick = !inBacktick;
                }

                if (!inMultiLineComment) cleanQuery += char;
                i++;
            }

            cleanQuery = cleanQuery.trim();
            cleanQuery = cleanQuery.split('\n').filter(line => line.trim() !== '').join('\n');

            return { cleanQuery, comments, hasComments: comments.length > 0 };
        }

        function updateCommentIndicator(sqlText) {
            const indicator = document.getElementById('comment-indicator');
            const parsed = parseSqlQuery(sqlText);
            if (parsed.hasComments) {
                indicator.textContent = `✓ ${parsed.comments.length} comment(s) detected`;
                indicator.className = 'comment-indicator has-comments';
            } else {
                indicator.textContent = 'No comments detected';
                indicator.className = 'comment-indicator';
            }
        }

        function isDataModificationQuery(sqlQuery) {
            if (!sqlQuery) return false;
            const trimmed = sqlQuery.trim().toUpperCase();
            const modificationKeywords = ['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'TRUNCATE', 'DROP', 'ALTER', 'CREATE'];
            return modificationKeywords.some(keyword => trimmed.startsWith(keyword));
        }

        function formatDataForCopyPure(data, columnMeta) {
            if (!data || data.length === 0) return '';
            let result = [];
            const headers = columnMeta.map(col => col.name);
            data.forEach(row => {
                const rowValues = headers.map(header => {
                    let value = row[header];
                    if (value === null || value === undefined) return 'NULL';
                    if (typeof value === 'object') return JSON.stringify(value, null, 2);
                    if (typeof value === 'string') {
                        const trimmed = value.trim();
                        if ((trimmed.startsWith('{') && trimmed.endsWith('}')) || 
                            (trimmed.startsWith('[') && trimmed.endsWith(']'))) {
                            try { return JSON.stringify(JSON.parse(trimmed), null, 2); } catch(e) { return value; }
                        }
                        return value;
                    }
                    return String(value);
                });
                result.push(rowValues.join('\t'));
            });
            return result.join('\n');
        }

        function copyQueryResultsPure() {
            if (!lastQueryResult) return;
            const { data, columnMeta } = lastQueryResult;
            if (!data || data.length === 0) return;
            const formattedData = formatDataForCopyPure(data, columnMeta);
            if (formattedData) {
                copyPureDataToClipboard(formattedData);
                const copyBtn = document.getElementById('copy-results-btn');
                if (copyBtn) {
                    copyBtn.textContent = '✓ Copied!';
                    copyBtn.classList.add('copied');
                    setTimeout(() => {
                        copyBtn.textContent = '📋 Copy Results';
                        copyBtn.classList.remove('copied');
                    }, 2000);
                }
            }
        }

        function copyQueryResults() { copyQueryResultsPure(); }

        function copyQueryResultDirectly() {
            const resultDiv = document.getElementById('query-result');
            if (!resultDiv) return;
            if (lastQueryResult && lastQueryResult.data && lastQueryResult.data.length > 0) {
                const formattedData = formatDataForCopyPure(lastQueryResult.data, lastQueryResult.columnMeta);
                if (formattedData) { copyPureDataToClipboard(formattedData); return; }
            }
            const table = resultDiv.querySelector('table');
            if (table) {
                let resultText = '';
                const rows = table.querySelectorAll('tr');
                let isFirstRow = true;
                rows.forEach(row => {
                    if (isFirstRow) { isFirstRow = false; return; }
                    const cells = row.querySelectorAll('td');
                    const rowText = Array.from(cells).map(cell => cell.textContent.trim()).join('\t');
                    if (rowText) resultText += rowText + '\n';
                });
                if (resultText) copyPureDataToClipboard(resultText.trim());
            }
        }

        function executeQuery() {
            if (isQueryExecuting) return;
            const fullSqlText = document.getElementById('sql-query').value;
            const resultDiv = document.getElementById('query-result');
            if (!fullSqlText.trim()) { copyToClipboardSilent('invalid response'); return; }
            const parsed = parseSqlQuery(fullSqlText);
            const cleanQuery = parsed.cleanQuery;
            if (!cleanQuery) { copyToClipboardSilent('invalid response'); return; }
            const isModification = isDataModificationQuery(cleanQuery);
            isQueryExecuting = true;

            if (parsed.hasComments) {
                let commentInfo = `Executing query with ${parsed.comments.length} comment(s) removed:\n`;
                parsed.comments.forEach((comment, index) => {
                    commentInfo += `  ${index + 1}. ${comment.text.substring(0, 50)}${comment.text.length > 50 ? '...' : ''}\n`;
                });
                showMessage(commentInfo, 'info');
            }

            const executeBtn = document.getElementById('execute-btn');
            executeBtn.style.backgroundColor = '#28a745';
            setTimeout(() => { executeBtn.style.backgroundColor = '#007bff'; }, 300);
            copyToClipboardSilent('enter button activated');

            fetch('phpmyadmin_tablesfetch.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `sql_query=${encodeURIComponent(cleanQuery)}`
            })
            .then(response => response.json())
            .then(data => {
                if (isModification) {
                    if (data.status === 'success') {
                        let successMessage = 'Data updated successfully';
                        if (data.data && data.data.affectedRows !== undefined) {
                            successMessage = `${data.data.affectedRows} row(s) affected successfully`;
                        }
                        copyPureDataToClipboard(successMessage);
                        showMessage('✅ ' + successMessage, 'success');
                    } else {
                        const errorMessage = 'Error: ' + (data.message || 'Operation failed');
                        copyPureDataToClipboard(errorMessage);
                        showMessage(errorMessage, 'error');
                    }
                    resultDiv.innerHTML = '';
                    if (data.status === 'success') {
                        const affectedRows = data.data && data.data.affectedRows !== undefined ? data.data.affectedRows : 0;
                        resultDiv.innerHTML = `
                            <div class="result-header">
                                <span class="result-info">✅ ${affectedRows} row(s) affected</span>
                            </div>
                            <p style="padding: 15px; color: #155724; background-color: #d4edda; border-radius: 4px;">
                                Query executed successfully. ${affectedRows} row(s) affected.
                            </p>
                        `;
                    }
                    isQueryExecuting = false;
                    return;
                }

                if (data.status === 'success') showMessage(data.message, 'success');
                else { showMessage(data.message, 'error'); copyToClipboardSilent('invalid response'); }
                
                resultDiv.innerHTML = '';
                if (data.status === 'success' && data.data) {
                    if (data.data.rows && data.data.rows.length > 0) {
                        lastQueryResult = { data: data.data.rows, columnMeta: data.data.columnMeta };
                        let resultHeader = `
                            <div class="result-header">
                                <span class="result-info">📊 ${data.data.rows.length} row(s) returned</span>
                                <button class="copy-btn" id="copy-results-btn" onclick="copyQueryResults()">
                                    📋 Copy Results
                                </button>
                            </div>
                        `;
                        let tableHtml = resultHeader;
                        tableHtml += '<table><thead><tr>';
                        data.data.columnMeta.forEach(col => { tableHtml += `<th>${col.name}</th>`; });
                        tableHtml += '</tr></thead><tbody>';
                        data.data.rows.forEach(row => {
                            tableHtml += '<tr>';
                            Object.values(row).forEach(value => {
                                let displayValue = (typeof value === 'object' && value !== null) 
                                    ? JSON.stringify(value, null, 2) 
                                    : (value !== null ? String(value) : 'NULL');
                                if (typeof value === 'string') {
                                    const trimmed = value.trim();
                                    if ((trimmed.startsWith('{') && trimmed.endsWith('}')) || 
                                        (trimmed.startsWith('[') && trimmed.endsWith(']'))) {
                                        try { displayValue = JSON.stringify(JSON.parse(trimmed), null, 2); } catch(e) {}
                                    }
                                }
                                tableHtml += `<td><pre style="margin:0; white-space:pre-wrap;">${displayValue}</pre></td>`;
                            });
                            tableHtml += '</tr>';
                        });
                        tableHtml += '</tbody></table>';
                        resultDiv.innerHTML = tableHtml;
                    } else if (data.data.affectedRows !== undefined) {
                        resultDiv.innerHTML = `
                            <div class="result-header">
                                <span class="result-info">✅ Affected rows: ${data.data.affectedRows}</span>
                            </div>
                            <p style="padding: 15px;">Query executed successfully. ${data.data.affectedRows} row(s) affected.</p>
                        `;
                        lastQueryResult = null;
                    } else {
                        resultDiv.innerHTML = `
                            <div class="result-header">
                                <span class="result-info">✅ Query executed successfully</span>
                            </div>
                            <p style="padding: 15px;">No data returned.</p>
                        `;
                        lastQueryResult = null;
                    }
                } else {
                    copyToClipboardSilent('invalid response');
                }
                isQueryExecuting = false;
            })
            .catch(error => {
                showMessage('Error: ' + error.message, 'error');
                lastQueryResult = null;
                copyToClipboardSilent('invalid response');
                isQueryExecuting = false;
            });
        }

        function handleKeyboardShortcut(event) {
            const textarea = document.getElementById('sql-query');

            if (event.type === 'keydown' && event.ctrlKey && (event.key === 'r' || event.key === 'R')) {
                isCtrlAPressed = true;
                return;
            }

            if (event.type === 'keydown' && isCtrlAPressed && (event.key === 'Delete' || event.key === 'Del' || event.key === 'Backspace')) {
                if (document.activeElement === textarea || textarea.value.length > 0) {
                    event.preventDefault();
                    event.stopPropagation();
                    resetEverything();
                    isCtrlAPressed = false;
                    return;
                }
            }

            if (event.type === 'keydown' && !(event.ctrlKey && (event.key === 'a' || event.key === 'A'))) {
                if (!event.ctrlKey || (event.key !== 'a' && event.key !== 'A' && event.key !== 'Delete' && event.key !== 'Del' && event.key !== 'Backspace')) {
                    isCtrlAPressed = false;
                }
            }

            if (event.key === 'Tab') {
                isTabPressed = event.type === 'keydown';
                if (event.type === 'keydown') {
                    event.preventDefault();
                    textarea.focus();
                    textarea.classList.add('tab-active');
                    copyToClipboardSilent('text area is on focus');
                    if (tabTimeout) clearTimeout(tabTimeout);
                }
            }

            if (event.type === 'keydown' && event.ctrlKey && (event.key === 'x' || event.key === 'X')) {
                event.preventDefault();
                copyQueryResultsPure();
                return;
            }

            if (event.type === 'keydown' && event.ctrlKey && (event.key === 'm' || event.key === 'M')) {
                event.preventDefault();
                copyQueryResultDirectly();
                return;
            }

            if (event.type === 'keydown' && event.key === 'Enter' && !event.shiftKey) {
                if (document.activeElement === textarea && !event.shiftKey) {
                    event.preventDefault();
                    executeQuery();
                    const executeBtn = document.getElementById('execute-btn');
                    executeBtn.style.backgroundColor = '#28a745';
                    setTimeout(() => { executeBtn.style.backgroundColor = '#007bff'; }, 300);
                }
            }
        }

        function handleKeyUp(event) {
            const textarea = document.getElementById('sql-query');
            if (event.target === textarea) updateCommentIndicator(textarea.value);
            if (event.key === 'Control' || event.key === 'a' || event.key === 'A') {
                setTimeout(() => { isCtrlAPressed = false; }, 100);
            }
            if (event.key === 'Tab') {
                isTabPressed = false;
                if (tabTimeout) clearTimeout(tabTimeout);
                tabTimeout = setTimeout(() => {
                    textarea.classList.remove('tab-active');
                    textarea.style.borderColor = '';
                    textarea.style.boxShadow = '';
                    textarea.style.backgroundColor = '';
                }, 300);
            }
        }

        function handleTextareaFocus() {
            const textarea = document.getElementById('sql-query');
            textarea.addEventListener('focus', function() {
                if (!isTabPressed) {
                    this.style.borderColor = '#28a745';
                    this.style.boxShadow = '0 0 0 3px rgba(40, 167, 69, 0.2)';
                }
            });
            textarea.addEventListener('blur', function() {
                if (!this.classList.contains('tab-active')) {
                    this.style.borderColor = '';
                    this.style.boxShadow = '';
                    this.style.backgroundColor = '';
                }
            });
            textarea.addEventListener('input', function() {
                updateCommentIndicator(this.value);
            });
        }

        function autoFocusTextarea() {
            const textarea = document.getElementById('sql-query');
            setTimeout(() => {
                textarea.focus();
                updateCommentIndicator(textarea.value);
                if (!isPageReady) {
                    isPageReady = true;
                    copyToClipboardSilent('page is ready');
                }
            }, 500);
        }

        document.addEventListener('DOMContentLoaded', () => {
            loadTables();
            tablePollInterval = setInterval(loadTables, 5000);
            
            document.getElementById('table-select').addEventListener('change', (e) => {
                const selectedTable = e.target.value;
                cachedColumns = [];
                loadColumns(selectedTable);
                if (columnPollInterval) clearInterval(columnPollInterval);
                if (selectedTable) {
                    columnPollInterval = setInterval(() => loadColumns(selectedTable), 5000);
                }
            });

            document.addEventListener('keydown', handleKeyboardShortcut);
            document.addEventListener('keyup', handleKeyUp);
            
            handleTextareaFocus();
            autoFocusTextarea();
            
            const initialText = document.getElementById('sql-query').value;
            if (initialText) updateCommentIndicator(initialText);
            
            // AUTO-PROCESS: Check and process uploaded JPGs on load
            setTimeout(() => {
                checkAndAutoProcessUploadedJpgs();
            }, 800); // Small delay to ensure page is fully loaded
        });

        window.addEventListener('unload', () => {
            if (tablePollInterval) clearInterval(tablePollInterval);
            if (columnPollInterval) clearInterval(columnPollInterval);
            document.removeEventListener('keydown', handleKeyboardShortcut);
            document.removeEventListener('keyup', handleKeyUp);
            if (tabTimeout) clearTimeout(tabTimeout);
        });
    </script>
</body>
</html>