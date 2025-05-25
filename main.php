<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Meta DSL Runtime (Client)</title>
  <style>
    input, button, textarea, select { margin: 5px; }
    fieldset { border: 1px solid #ccc; padding: 10px; margin: 10px 0; }
    legend { font-weight: bold; }
    .flex { display: flex; gap: 2em; }
    .box { flex: 1; min-width: 350px; }
  </style>
</head>
<body>
  <h1>Meta DSL Client</h1>
  <div class="flex">
    <div class="box">
      <h2>DSL Input</h2>
      <textarea id="dslInput" rows="6" cols="60" placeholder="let person = object(name: 'Alice', age: 30);">let person = object(name: "Alice", age: 30);
tag person as api, post, path:/api_v1/greet_person;</textarea><br>
      <button onclick="runDSL()">Run DSL</button>
      <h3>DSL Parsed Objects</h3>
      <pre id="output"></pre>
    </div>
    <div class="box">
      <h2>Call Server API</h2>
      <select id="apiSelect"></select>
      <div id="argInputs"></div>
      <button onclick="callServerApi()">Call API</button>
      <h3>API Raw Response</h3>
      <pre id="apiResult"></pre>
      <h3>Reconstructed Object from Response</h3>
      <pre id="reObjResult"></pre>
    </div>
  </div>
<script>
// ---- Core Classes ----

class MetaVar {
  constructor(name, value, type = "mixed") {
    this.name = name;
    this.value = value;
    this.type = type;
  }
  get() { return this.value; }
  set(v) { this.value = v; }
  describe() { return { name: this.name, value: this.value, type: this.type }; }
}

class AbstractObject {
  constructor(id) {
    this.id = id;
    this.fields = {};
  }
  addVar(name, value, type = "mixed") {
    this.fields[name] = new MetaVar(name, value, type);
    return this;
  }
  set(name, value) {
    if (this.fields[name]) this.fields[name].set(value);
  }
  get(name) {
    return this.fields[name]?.get();
  }
  toJSON() {
    const obj = {};
    for (const k in this.fields) obj[k] = this.fields[k].get();
    return obj;
  }
  describe() {
    const out = {};
    for (const k in this.fields) out[k] = this.fields[k].describe();
    return { id: this.id, fields: out };
  }
}

// MetaObject/MetaCompiler: handle the DSL and API
class MetaObject {
  constructor(id, type = "generic", value = null) {
    this.id = id;
    this.type = type;
    this.value = value;
    this.tags = [];
  }
  tag(...tags) {
    for (const t of tags) if (!this.tags.includes(t)) this.tags.push(t);
  }
  describe() {
    return {
      id: this.id,
      type: this.type,
      value: this.value instanceof AbstractObject ? this.value.describe() : this.value,
      tags: this.tags
    };
  }
}

class MetaCompiler {
  constructor() { this.objects = {}; }
  addObject(obj) { this.objects[obj.id] = obj; }
  get(id) { return this.objects[id]; }
  compile(code) {
    this.objects = {};
    const lines = code.split(/;\s*\n?/).map(l => l.trim()).filter(Boolean);
    for (const line of lines) {
      if (line.startsWith('let ') && line.includes('object(')) {
        this.handleObjectDefine(line);
      } else if (line.startsWith('tag ')) {
        this.handleTag(line);
      }
    }
    populateApiDropdown();
  }

  handleObjectDefine(line) {
    const match = line.match(/^let (\w+)\s*=\s*object\((.*)\)$/s);
    if (!match) return;
    const [, id, raw] = match;
    const props = raw.split(',').map(p => p.trim()).filter(Boolean);
    const obj = new AbstractObject(id);
    for (const prop of props) {
      const [key, value] = prop.split(':').map(x => x.trim().replace(/^["']|["']$/g, ''));
      obj.addVar(key, value);
    }
    const metaObj = new MetaObject(id, 'abstract-object', obj);
    this.addObject(metaObj);
  }

  handleTag(line) {
    const match = line.match(/^tag (\w+) as (.+)$/);
    if (!match) return;
    const [, id, tagstr] = match;
    const tags = tagstr.split(',').map(t => t.trim());
    const obj = this.objects[id];
    if (obj) obj.tag(...tags);
  }
}

// --- Reconstruct AbstractObject from Server Describe ---
function reconstructAbstractObjectFromDescribe(describeObj) {
  if (!describeObj || !describeObj.id || !describeObj.relations || !describeObj.relations.field) return null;
  const abs = new AbstractObject(describeObj.id);
  for (const field of describeObj.relations.field) {
    // If field is a value, handle; if field is an object (e.g. nested), recurse
    if (typeof field === "object" && field !== null && "name" in field) {
      abs.addVar(field.name, field.value, field.type);
    } else if (typeof field === "string") {
      // fallback: just name, value unknown
      abs.addVar(field, undefined);
    }
  }
  return abs;
}

const compiler = new MetaCompiler();
let inputBindings = {};

function runDSL() {
  compiler.compile(document.getElementById('dslInput').value);
  const out = {};
  for (const [id, obj] of Object.entries(compiler.objects)) {
    out[id] = obj.describe();
  }
  document.getElementById('output').textContent = JSON.stringify(out, null, 2);
}

function populateApiDropdown() {
  const select = document.getElementById('apiSelect');
  select.innerHTML = '';
  Object.values(compiler.objects).forEach(obj => {
    if (obj.tags.includes('api')) {
      const pathTag = obj.tags.find(t => t.startsWith('path:'));
      if (pathTag) {
        const opt = document.createElement('option');
        opt.value = pathTag.split(':')[1];
        opt.textContent = `${obj.id} → ${opt.value}`;
        select.appendChild(opt);
      }
    }
  });
  renderArgInputs();
}

function renderArgInputs() {
  const container = document.getElementById('argInputs');
  container.innerHTML = '';
  inputBindings = {};
  const path = document.getElementById('apiSelect').value;
  const apiObject = Object.values(compiler.objects).find(o => o.tags.includes(`path:${path}`));
  if (!apiObject || apiObject.type !== 'abstract-object') return;

  const objInstance = apiObject.value;
  inputBindings[apiObject.id] = objInstance;

  const fieldset = document.createElement('fieldset');
  const legend = document.createElement('legend');
  legend.textContent = apiObject.id;
  fieldset.appendChild(legend);

  for (const key in objInstance.fields) {
    const input = document.createElement('input');
    input.placeholder = key;
    input.value = objInstance.get(key) || '';
    input.addEventListener('input', e => objInstance.set(key, e.target.value));
    fieldset.appendChild(input);
    fieldset.appendChild(document.createElement('br'));
  }

  container.appendChild(fieldset);
}

function callServerApi() {
  const path = document.getElementById('apiSelect').value;
  const apiObject = Object.values(compiler.objects).find(o => o.tags.includes(`path:${path}`));
  const isPost = apiObject?.tags.includes('post');

  const payload = {};
  if (apiObject?.type === 'abstract-object') {
    payload[apiObject.id] = apiObject.value.toJSON();
  }

  const url = `/index.php?path=${encodeURIComponent(path)}`;

  if (isPost) {
    fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
      document.getElementById('apiResult').textContent = JSON.stringify(data, null, 2);

      // --- Reconstruct AbstractObject from response ---
      try {
        const vars = data?.result?.vars;
        if (Array.isArray(vars) && vars.length) {
          const describeObj = vars[0].value; // assumes MetaObject::describe() output as value
          const reObj = reconstructAbstractObjectFromDescribe(describeObj);
          document.getElementById('reObjResult').textContent = reObj
            ? JSON.stringify(reObj.describe(), null, 2)
            : "(Cannot reconstruct: missing relations.field info)";
        } else {
          document.getElementById('reObjResult').textContent = "No object in response.";
        }
      } catch (e) {
        document.getElementById('reObjResult').textContent = "Error: " + e;
      }
    })
    .catch(err => {
      document.getElementById('apiResult').textContent = `Error: ${err}`;
      document.getElementById('reObjResult').textContent = "";
    });
  } else {
    const query = Object.entries(payload[apiObject.id])
      .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`)
      .join('&');
    fetch(`${url}&${query}`)
      .then(res => res.json())
      .then(data => {
        document.getElementById('apiResult').textContent = JSON.stringify(data, null, 2);
      })
      .catch(err => {
        document.getElementById('apiResult').textContent = `Error: ${err}`;
      });
  }
}

window.addEventListener('DOMContentLoaded', () => {
  document.getElementById('apiSelect').addEventListener('change', renderArgInputs);
  runDSL();
});
</script>
</body>
</html>
