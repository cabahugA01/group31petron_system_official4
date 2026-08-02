const fs = require('fs');

const file = 'public/staff_inventory_fuel.php';
let content = fs.readFileSync(file, 'utf8');

// Count occurrences before
const beforeCount = (content.match(/—/g) || []).length;
console.log(`Found ${beforeCount} instances of malformed characters`);

// Replace malformed UTF-8 em-dash with simple dash
content = content.replace(/—/g, '-');

// Write back
fs.writeFileSync(file, content, 'utf8');

console.log('Fixed character encoding in ' + file);
console.log('All malformed UTF-8 characters have been replaced with dashes.');
