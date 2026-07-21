function scalar(value) {
  const text = value.trim();
  if (text === '') return {};
  if (text === 'true') return true;
  if (text === 'false') return false;
  if (text === 'null' || text === '~') return null;
  if (/^-?\d+(\.\d+)?$/.test(text)) return Number(text);
  if ((text.startsWith('"') && text.endsWith('"')) || (text.startsWith("'") && text.endsWith("'"))) return text.slice(1, -1);
  if (text === '[]') return [];
  if (text === '{}') return {};
  return text;
}

export function parseSimpleYaml(input) {
  const root = {};
  const stack = [{ indent: -1, value: root }];
  const lines = String(input).replace(/\t/g, '  ').split(/\r?\n/);

  for (let index = 0; index < lines.length; index += 1) {
    const raw = lines[index];
    if (!raw.trim() || raw.trimStart().startsWith('#')) continue;
    const indent = raw.match(/^ */)[0].length;
    const content = raw.trim();
    while (stack.length > 1 && indent <= stack.at(-1).indent) stack.pop();
    const parent = stack.at(-1).value;

    if (content.startsWith('- ')) {
      if (!Array.isArray(parent)) throw new SyntaxError(`Unexpected list item at line ${index + 1}`);
      const itemText = content.slice(2).trim();
      if (itemText.includes(':')) {
        const separator = itemText.indexOf(':');
        const key = itemText.slice(0, separator).trim();
        const rest = itemText.slice(separator + 1).trim();
        const item = {};
        item[key] = scalar(rest);
        parent.push(item);
        stack.push({ indent, value: item });
      } else parent.push(scalar(itemText));
      continue;
    }

    const separator = content.indexOf(':');
    if (separator < 1) throw new SyntaxError(`Expected key:value at line ${index + 1}`);
    const key = content.slice(0, separator).trim();
    const rest = content.slice(separator + 1).trim();
    if (rest !== '') {
      parent[key] = scalar(rest);
      continue;
    }

    let nextMeaningful = '';
    let nextIndent = -1;
    for (let cursor = index + 1; cursor < lines.length; cursor += 1) {
      if (!lines[cursor].trim() || lines[cursor].trimStart().startsWith('#')) continue;
      nextMeaningful = lines[cursor].trim();
      nextIndent = lines[cursor].match(/^ */)[0].length;
      break;
    }
    const container = nextIndent > indent && nextMeaningful.startsWith('- ') ? [] : {};
    parent[key] = container;
    stack.push({ indent, value: container });
  }
  return root;
}
