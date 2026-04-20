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
            grid-template-columns: 1fr 2fr;
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
        select:focus, textarea:focus, button:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 5px rgba(0, 123, 255, 0.3);
        }
        textarea {
            height: 120px;
            resize: vertical;
        }
        button {
            background-color: #007bff;
            color: white;
            border: none;
            cursor: pointer;
            font-weight: bold;
            transition: background-color 0.2s;
        }
        button:hover {
            background-color: #0056b3;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            background-color: #fff;
            border: 1px solid #ddd;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
            font-size: 14px;
        }
        th {
            background-color: #f8f9fa;
            color: #333;
            font-weight: bold;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .error {
            color: #721c24;
            padding: 10px;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        .success {
            color: #155724;
            padding: 10px;
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        .query-result, .column-data {
            margin-top: 15px;
        }
        .column-data table {
            max-width: 500px; /* Limit width for single-column display */
        }
        @media (max-width: 768px) {
            .container {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <h1>Database Query Interface</h1>
    
    <div id="message" class="success"></div>
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
            <label for="sql-query">SQL Query</label>
            <textarea id="sql-query" placeholder="Enter your SQL query (e.g., SELECT * FROM users)"></textarea>
            <button onclick="executeQuery()">Execute Query</button>
            <div id="query-result" class="query-result"></div>
            <div id="column-data" class="column-data"></div>
        </div>
    </div>

    <script>
        let tablePollInterval = null;
        let columnPollInterval = null;
        let cachedTables = [];
        let cachedColumns = [];

        // Function to fetch and populate tables
        function loadTables() {
            fetch('phpmyadmin_tablesfetch.php')
                .then(response => response.json())
                .then(data => {
                    const messageDiv = document.getElementById('message');
                    const tableSelect = document.getElementById('table-select');
                    const currentTable = tableSelect.value;

                    if (data.status !== 'success') {
                        messageDiv.className = 'error';
                        messageDiv.textContent = data.message;
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
                            if (table === selectedTable) {
                                option.selected = true;
                            }
                            tableSelect.appendChild(option);
                        });

                        if (selectedTable) {
                            loadColumns(selectedTable);
                        } else {
                            document.getElementById('column-select').innerHTML = '';
                            if (columnPollInterval) {
                                clearInterval(columnPollInterval);
                                columnPollInterval = null;
                            }
                        }

                        if (!messageDiv.textContent || newTables.length === 0) {
                            messageDiv.className = 'success';
                            messageDiv.textContent = newTables.length > 0 ? 'Tables retrieved successfully' : 'No tables found in the database.';
                        }
                    }
                })
                .catch(error => {
                    const messageDiv = document.getElementById('message');
                    messageDiv.className = 'error';
                    messageDiv.textContent = 'Error fetching tables: ' + error.message;
                    window.location.href = 'phpmyadmintemplate.php';
                });
        }

        // Function to fetch and populate columns
        function loadColumns(table) {
            if (!table) return;

            fetch(`phpmyadmin_tablesfetch.php?table=${encodeURIComponent(table)}`)
                .then(response => response.json())
                .then(data => {
                    const messageDiv = document.getElementById('message');
                    const columnSelect = document.getElementById('column-select');
                    const currentColumn = columnSelect.value;

                    if (data.status !== 'success') {
                        messageDiv.className = 'error';
                        messageDiv.textContent = data.message;
                        columnSelect.innerHTML = '';
                        return;
                    }

                    const newColumns = data.columns.map(col => col.Field);
                    if (JSON.stringify(newColumns.sort()) !== JSON.stringify(cachedColumns.sort())) {
                        cachedColumns = newColumns;
                        const selectedColumn = currentColumn && newColumns.includes(currentColumn) ? currentColumn : (newColumns[0] || '');

                        columnSelect.innerHTML = '';
                        data.columns.forEach(column => {
                            const option = document.createElement('option');
                            option.value = column.Field;
                            option.textContent = `${column.Field} (${column.Type})`;
                            if (column.Field === selectedColumn) {
                                option.selected = true;
                            }
                            columnSelect.appendChild(option);
                        });

                        if (!currentColumn || selectedColumn) {
                            messageDiv.className = 'success';
                            messageDiv.textContent = data.message;
                        }
                    }
                })
                .catch(error => {
                    const messageDiv = document.getElementById('message');
                    messageDiv.className = 'error';
                    messageDiv.textContent = 'Error fetching columns: ' + error.message;
                    document.getElementById('column-select').innerHTML = '';
                });
        }

        // Function to execute SQL query
        function executeQuery() {
            const sqlQuery = document.getElementById('sql-query').value.trim();
            const messageDiv = document.getElementById('message');
            const resultDiv = document.getElementById('query-result');
            const columnDataDiv = document.getElementById('column-data');
            const queryInput = document.getElementById('sql-query');

            if (!sqlQuery) {
                messageDiv.className = 'error';
                messageDiv.textContent = 'Please enter an SQL query.';
                resultDiv.innerHTML = '';
                columnDataDiv.innerHTML = '';
                return;
            }

            fetch('phpmyadmin_tablesfetch.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `sql_query=${encodeURIComponent(sqlQuery)}`
            })
                .then(response => response.json())
                .then(data => {
                    messageDiv.className = data.status === 'success' ? 'success' : 'error';
                    messageDiv.textContent = data.message;
                    resultDiv.innerHTML = '';
                    columnDataDiv.innerHTML = '';

                    if (data.status === 'success') {
                        queryInput.value = ''; // Clear textarea

                        // Check if query is a single-column SELECT
                        const isSingleColumnSelect = sqlQuery.match(/^\s*SELECT\s+([a-zA-Z0-9_]+)\s+FROM\s+[a-zA-Z0-9_]+/i) && data.data.columnMeta && data.data.columnMeta.length === 1;

                        if (data.data.rows && data.data.rows.length > 0) {
                            // Handle SELECT queries
                            if (isSingleColumnSelect) {
                                const columnName = data.data.columnMeta[0].name;
                                let columnHtml = `<h3>Data for Column: ${columnName}</h3><table><tr><th>${columnName}</th></tr>`;
                                data.data.rows.forEach(row => {
                                    const value = row[columnName] !== null ? row[columnName] : 'NULL';
                                    columnHtml += `<tr><td>${value}</td></tr>`;
                                });
                                columnHtml += '</table>';
                                columnDataDiv.innerHTML = columnHtml;

                                let tableHtml = '<h3>Query Results</h3><table><tr>';
                                data.data.columnMeta.forEach(col => {
                                    tableHtml += `<th>${col.name}</th>`;
                                });
                                tableHtml += '</tr>';
                                data.data.rows.forEach(row => {
                                    tableHtml += '<tr>';
                                    Object.values(row).forEach(value => {
                                        tableHtml += `<td>${value !== null ? value : 'NULL'}</td>`;
                                    });
                                    tableHtml += '</tr>';
                                });
                                tableHtml += '</table>';
                                resultDiv.innerHTML = tableHtml;
                            } else {
                                let tableHtml = '<h3>Query Results</h3><table><tr>';
                                data.data.columnMeta.forEach(col => {
                                    tableHtml += `<th>${col.name}</th>`;
                                });
                                tableHtml += '</tr>';
                                data.data.rows.forEach(row => {
                                    tableHtml += '<tr>';
                                    Object.values(row).forEach(value => {
                                        tableHtml += `<td>${value !== null ? value : 'NULL'}</td>`;
                                    });
                                    tableHtml += '</tr>';
                                });
                                tableHtml += '</table>';
                                resultDiv.innerHTML = tableHtml;
                            }
                        } else if (data.data.affectedRows !== undefined) {
                            // Handle non-SELECT queries (UPDATE, INSERT, ALTER, etc.)
                            const queryType = sqlQuery.match(/^\s*(UPDATE|INSERT|ALTER|DELETE)/i)?.[1]?.toUpperCase() || 'Query';
                            resultDiv.innerHTML = `<p>${queryType} executed successfully. Affected rows: ${data.data.affectedRows}</p>`;
                        } else {
                            resultDiv.innerHTML = '<p>Query executed successfully, but no results returned.</p>';
                        }
                    }
                })
                .then(() => {
                    loadTables();
                    const selectedTable = document.getElementById('table-select').value;
                    if (selectedTable) {
                        loadColumns(selectedTable);
                    }
                })
                .catch(error => {
                    messageDiv.className = 'error';
                    messageDiv.textContent = 'Error executing query: ' + error.message;
                    resultDiv.innerHTML = '';
                    columnDataDiv.innerHTML = '';
                });
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            loadTables();
            tablePollInterval = setInterval(loadTables, 5000);
            document.getElementById('table-select').addEventListener('change', (e) => {
                const selectedTable = e.target.value;
                cachedColumns = [];
                loadColumns(selectedTable);
                if (columnPollInterval) {
                    clearInterval(columnPollInterval);
                    columnPollInterval = null;
                }
                if (selectedTable) {
                    columnPollInterval = setInterval(() => loadColumns(selectedTable), 5000);
                }
            });
        });

        window.addEventListener('unload', () => {
            if (tablePollInterval) clearInterval(tablePollInterval);
            if (columnPollInterval) clearInterval(columnPollInterval);
        });
    </script>
</body>
</html>