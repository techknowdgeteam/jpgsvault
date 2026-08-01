<?php
// phpmyadmintemplate.php
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
        /* Focus state for textarea */
        textarea:focus {
            outline: none;
            border-color: #28a745;
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.2);
        }
        /* Green border when Tab is active */
        textarea.tab-active {
            border-color: #28a745;
            border-width: 3px;
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.3);
            background-color: #f0fff0;
        }
        
        /* Syntax highlighting for comments in textarea */
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

        @media (max-width: 768px) {
            .container { grid-template-columns: 1fr; }
            .result-header { flex-direction: column; gap: 10px; align-items: stretch; }
        }
    </style>
</head>
<body>
    <h1>Database Query Interface</h1>
    
    <div id="message"></div>
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
                        <strong>Tab</strong> to focus | <strong>Enter</strong> to execute | <strong>Ctrl+C</strong> to copy results
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
        let cachedTables = [];
        let cachedColumns = [];
        let isTabPressed = false;
        let tabTimeout = null;
        let lastQueryResult = null;

        function showMessage(text, type = 'info') {
            const messageDiv = document.getElementById('message');
            messageDiv.textContent = text;
            messageDiv.className = type;
            messageDiv.style.display = 'block';
            
            // Auto-hide info messages after 3 seconds
            if (type === 'info') {
                setTimeout(() => {
                    messageDiv.style.display = 'none';
                }, 3000);
            }
        }

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

        /**
         * Parse SQL query to remove comments and identify comment sections
         * Supports:
         * - Single line comments: -- comment
         * - Single line comments: # comment
         * - Multi-line comments: /* comment *\/
         */
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
            let currentComment = '';
            let commentStartLine = 1;
            let lineNumber = 1;
            
            // Track line numbers for each character
            const lines = sqlText.split('\n');
            let currentPosition = 0;

            while (i < sqlText.length) {
                const char = sqlText[i];
                const nextChar = sqlText[i + 1] || '';
                const prevChar = sqlText[i - 1] || '';
                
                // Update line number
                if (char === '\n') {
                    lineNumber++;
                }

                // Check for single-line comment: -- or #
                if (!inMultiLineComment && !inSingleQuote && !inDoubleQuote && !inBacktick) {
                    if ((char === '-' && nextChar === '-') || char === '#') {
                        // Start of single-line comment
                        let commentStart = i;
                        let commentEnd = sqlText.indexOf('\n', i);
                        if (commentEnd === -1) commentEnd = sqlText.length;
                        
                        // Extract the comment
                        let commentText = sqlText.substring(commentStart, commentEnd).trim();
                        comments.push({
                            type: 'single-line',
                            text: commentText,
                            line: lineNumber,
                            start: commentStart,
                            end: commentEnd
                        });
                        
                        // Skip to end of line
                        i = commentEnd;
                        continue;
                    }
                }

                // Check for multi-line comment start: /*
                if (!inMultiLineComment && !inSingleQuote && !inDoubleQuote && !inBacktick) {
                    if (char === '/' && nextChar === '*') {
                        inMultiLineComment = true;
                        let commentStart = i;
                        // Find the end of the comment
                        let commentEnd = sqlText.indexOf('*/', i + 2);
                        if (commentEnd === -1) {
                            commentEnd = sqlText.length;
                        } else {
                            commentEnd += 2; // Include the */
                        }
                        
                        let commentText = sqlText.substring(commentStart, commentEnd).trim();
                        comments.push({
                            type: 'multi-line',
                            text: commentText,
                            line: lineNumber,
                            start: commentStart,
                            end: commentEnd
                        });
                        
                        i = commentEnd;
                        inMultiLineComment = false;
                        continue;
                    }
                }

                // Handle string delimiters - we need to ignore comments inside strings
                if (!inMultiLineComment) {
                    if (char === "'" && !inDoubleQuote && !inBacktick) {
                        inSingleQuote = !inSingleQuote;
                    } else if (char === '"' && !inSingleQuote && !inBacktick) {
                        inDoubleQuote = !inDoubleQuote;
                    } else if (char === '`' && !inSingleQuote && !inDoubleQuote) {
                        inBacktick = !inBacktick;
                    }
                }

                // If we're not in a multi-line comment, add character to clean query
                if (!inMultiLineComment) {
                    cleanQuery += char;
                }

                i++;
            }

            // Clean up the query: remove trailing whitespace and ensure proper formatting
            cleanQuery = cleanQuery.trim();
            
            // Remove empty lines
            cleanQuery = cleanQuery.split('\n')
                .filter(line => line.trim() !== '')
                .join('\n');

            return {
                cleanQuery: cleanQuery,
                comments: comments,
                hasComments: comments.length > 0
            };
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

        /**
         * Format data for copying
         */
        function formatDataForCopy(data, columnMeta) {
            if (!data || data.length === 0) {
                return 'No results to copy';
            }

            let result = [];
            
            // Add header
            const headers = columnMeta.map(col => col.name);
            result.push(headers.join('\t'));
            
            // Add rows
            data.forEach(row => {
                const rowValues = headers.map(header => {
                    let value = row[header];
                    
                    // Handle special values
                    if (value === null || value === undefined) {
                        return 'NULL';
                    }
                    
                    // If value is an object or array, convert to JSON
                    if (typeof value === 'object') {
                        return JSON.stringify(value, null, 2);
                    }
                    
                    // If it's a string that looks like JSON, parse and format it
                    if (typeof value === 'string') {
                        const trimmed = value.trim();
                        if ((trimmed.startsWith('{') && trimmed.endsWith('}')) || 
                            (trimmed.startsWith('[') && trimmed.endsWith(']'))) {
                            try {
                                const parsed = JSON.parse(trimmed);
                                return JSON.stringify(parsed, null, 2);
                            } catch (e) {
                                // Not valid JSON, return as is
                                return value;
                            }
                        }
                        // Check if it's a comma-separated list
                        if (trimmed.includes(',') && !trimmed.includes(' ')) {
                            const items = trimmed.split(',').map(item => item.trim());
                            return JSON.stringify(items, null, 2);
                        }
                        return value;
                    }
                    
                    return String(value);
                });
                result.push(rowValues.join('\t'));
            });
            
            return result.join('\n');
        }

        /**
         * Copy query results to clipboard
         */
        function copyQueryResults() {
            if (!lastQueryResult) {
                showMessage('No results available to copy. Please execute a query first.', 'info');
                return;
            }

            const { data, columnMeta } = lastQueryResult;
            
            if (!data || data.length === 0) {
                showMessage('No data to copy.', 'info');
                return;
            }

            // Format data for copying
            const formattedData = formatDataForCopy(data, columnMeta);
            
            // Use modern clipboard API
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(formattedData)
                    .then(() => {
                        showMessage(`✅ Copied ${data.length} row(s) to clipboard (${formattedData.split('\n').length} lines)`, 'success');
                        // Update button style
                        const copyBtn = document.getElementById('copy-results-btn');
                        if (copyBtn) {
                            copyBtn.textContent = '✓ Copied!';
                            copyBtn.classList.add('copied');
                            setTimeout(() => {
                                copyBtn.textContent = '📋 Copy Results';
                                copyBtn.classList.remove('copied');
                            }, 2000);
                        }
                    })
                    .catch(err => {
                        // Fallback method
                        fallbackCopy(formattedData);
                    });
            } else {
                // Fallback for older browsers
                fallbackCopy(formattedData);
            }
        }

        /**
         * Fallback copy method using textarea
         */
        function fallbackCopy(text) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            
            textarea.select();
            try {
                const successful = document.execCommand('copy');
                if (successful) {
                    showMessage(`✅ Copied successfully using fallback method`, 'success');
                } else {
                    showMessage('Failed to copy. Please copy manually.', 'error');
                }
            } catch (err) {
                showMessage('Copy failed: ' + err.message, 'error');
            }
            
            document.body.removeChild(textarea);
        }

        function executeQuery() {
            const fullSqlText = document.getElementById('sql-query').value;
            const resultDiv = document.getElementById('query-result');
            const columnDataDiv = document.getElementById('column-data');

            if (!fullSqlText.trim()) {
                showMessage('Please enter an SQL query.', 'info');
                return;
            }

            // Parse the query to separate comments from actual SQL
            const parsed = parseSqlQuery(fullSqlText);
            const cleanQuery = parsed.cleanQuery;

            if (!cleanQuery) {
                showMessage('No valid SQL query found. Please enter a SQL query.', 'error');
                return;
            }

            // Log what we're executing
            console.log('Full text with comments:', fullSqlText);
            console.log('Clean SQL query:', cleanQuery);
            console.log('Comments found:', parsed.comments);

            // Show comment information
            if (parsed.hasComments) {
                let commentInfo = `Executing query with ${parsed.comments.length} comment(s) removed:\n`;
                parsed.comments.forEach((comment, index) => {
                    commentInfo += `  ${index + 1}. ${comment.text.substring(0, 50)}${comment.text.length > 50 ? '...' : ''}\n`;
                });
                showMessage(commentInfo, 'info');
            }

            // Visual feedback for execution
            const executeBtn = document.getElementById('execute-btn');
            executeBtn.style.backgroundColor = '#28a745';
            setTimeout(() => {
                executeBtn.style.backgroundColor = '#007bff';
            }, 300);

            // Execute the clean query (without comments)
            fetch('phpmyadmin_tablesfetch.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `sql_query=${encodeURIComponent(cleanQuery)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    showMessage(data.message, 'success');
                } else {
                    showMessage(data.message, 'error');
                }
                
                resultDiv.innerHTML = '';
                columnDataDiv.innerHTML = '';

                if (data.status === 'success' && data.data) {
                    if (data.data.rows && data.data.rows.length > 0) {
                        // Store result for copy functionality
                        lastQueryResult = {
                            data: data.data.rows,
                            columnMeta: data.data.columnMeta
                        };

                        // Build result header with copy button
                        let resultHeader = `
                            <div class="result-header">
                                <span class="result-info">📊 ${data.data.rows.length} row(s) returned</span>
                                <button class="copy-btn" id="copy-results-btn" onclick="copyQueryResults()">
                                    📋 Copy Results
                                </button>
                            </div>
                        `;

                        // Build table
                        let tableHtml = resultHeader;
                        tableHtml += '<table><thead><tr>';
                        data.data.columnMeta.forEach(col => {
                            tableHtml += `<th>${col.name}</th>`;
                        });
                        tableHtml += '</tr></thead><tbody>';

                        data.data.rows.forEach(row => {
                            tableHtml += '<tr>';
                            Object.values(row).forEach(value => {
                                let displayValue = (typeof value === 'object' && value !== null) 
                                    ? JSON.stringify(value, null, 2) 
                                    : (value !== null ? String(value) : 'NULL');
                                
                                // Check if it's a JSON string
                                if (typeof value === 'string') {
                                    const trimmed = value.trim();
                                    if ((trimmed.startsWith('{') && trimmed.endsWith('}')) || 
                                        (trimmed.startsWith('[') && trimmed.endsWith(']'))) {
                                        try {
                                            const parsedJson = JSON.parse(trimmed);
                                            displayValue = JSON.stringify(parsedJson, null, 2);
                                        } catch (e) {
                                            // Not valid JSON, use as is
                                        }
                                    }
                                }
                                
                                tableHtml += `<td><pre style="margin:0; white-space:pre-wrap;">${displayValue}</pre></td>`;
                            });
                            tableHtml += '</tr>';
                        });
                        tableHtml += '</tbody></table>';
                        resultDiv.innerHTML = tableHtml;

                    } else if (data.data.affectedRows !== undefined) {
                        // For non-SELECT queries
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
                }
            })
            .catch(error => {
                showMessage('Error: ' + error.message, 'error');
                lastQueryResult = null;
            });
        }

        // Function to handle keyboard shortcuts
        function handleKeyboardShortcut(event) {
            const textarea = document.getElementById('sql-query');

            // Track Tab key state
            if (event.key === 'Tab') {
                isTabPressed = event.type === 'keydown';
                if (event.type === 'keydown') {
                    event.preventDefault(); // Prevent default tab behavior
                    
                    // Focus the textarea
                    textarea.focus();
                    textarea.classList.add('tab-active');
                    
                    // Clear any existing timeout
                    if (tabTimeout) {
                        clearTimeout(tabTimeout);
                    }
                }
            }

            // Check if Ctrl+C is pressed - copy results
            if (event.type === 'keydown' && event.ctrlKey && event.key === 'c') {
                // Only copy if we're not in the textarea (to allow normal copy)
                if (document.activeElement !== textarea) {
                    event.preventDefault();
                    copyQueryResults();
                }
            }

            // Check if Enter key is pressed - execute query
            if (event.type === 'keydown' && event.key === 'Enter') {
                // Don't prevent default for Enter in textarea (allow newlines)
                // But if Enter is pressed anywhere, execute the query
                executeQuery();
                
                // Visual feedback on the execute button
                const executeBtn = document.getElementById('execute-btn');
                executeBtn.style.backgroundColor = '#28a745';
                setTimeout(() => {
                    executeBtn.style.backgroundColor = '#007bff';
                }, 300);
            }
        }

        // Function to handle key releases
        function handleKeyUp(event) {
            const textarea = document.getElementById('sql-query');
            
            // Update comment indicator on any key release in textarea
            if (event.target === textarea) {
                updateCommentIndicator(textarea.value);
            }
            
            // Track Tab key state
            if (event.key === 'Tab') {
                isTabPressed = false;
                
                // Clear any existing timeout
                if (tabTimeout) {
                    clearTimeout(tabTimeout);
                }
                
                // Remove the active state after a delay
                tabTimeout = setTimeout(() => {
                    textarea.classList.remove('tab-active');
                    textarea.style.borderColor = '';
                    textarea.style.boxShadow = '';
                    textarea.style.backgroundColor = '';
                }, 300);
            }
        }

        // Function to handle textarea focus events
        function handleTextareaFocus() {
            const textarea = document.getElementById('sql-query');
            
            textarea.addEventListener('focus', function() {
                // If Tab was used, keep the green border
                if (!isTabPressed) {
                    // Normal focus
                    this.style.borderColor = '#28a745';
                    this.style.boxShadow = '0 0 0 3px rgba(40, 167, 69, 0.2)';
                }
            });
            
            textarea.addEventListener('blur', function() {
                // Only remove styles if not in Tab active state
                if (!this.classList.contains('tab-active')) {
                    this.style.borderColor = '';
                    this.style.boxShadow = '';
                    this.style.backgroundColor = '';
                }
            });
            
            // Update comment indicator on input
            textarea.addEventListener('input', function() {
                updateCommentIndicator(this.value);
            });
        }

        // Function to auto-focus the textarea on page load
        function autoFocusTextarea() {
            const textarea = document.getElementById('sql-query');
            setTimeout(() => {
                textarea.focus();
                // Update comment indicator for initial content
                updateCommentIndicator(textarea.value);
            }, 500);
        }

        document.addEventListener('DOMContentLoaded', () => {
            // Load tables
            loadTables();
            tablePollInterval = setInterval(loadTables, 5000);
            
            // Set up table change listener
            document.getElementById('table-select').addEventListener('change', (e) => {
                const selectedTable = e.target.value;
                cachedColumns = [];
                loadColumns(selectedTable);
                if (columnPollInterval) clearInterval(columnPollInterval);
                if (selectedTable) {
                    columnPollInterval = setInterval(() => loadColumns(selectedTable), 5000);
                }
            });

            // Set up keyboard shortcut listeners
            document.addEventListener('keydown', handleKeyboardShortcut);
            document.addEventListener('keyup', handleKeyUp);
            
            // Set up textarea focus handlers
            handleTextareaFocus();
            
            // Auto-focus the textarea on page load
            autoFocusTextarea();
            
            // Initial comment detection
            const initialText = document.getElementById('sql-query').value;
            if (initialText) {
                updateCommentIndicator(initialText);
            }
        });

        // Clean up event listeners on page unload
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
