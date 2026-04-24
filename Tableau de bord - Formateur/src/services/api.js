function normalizeBase(url) {
  return String(url || "").trim().replace(/\/+$/, "");
}

function getApiBaseCandidates() {
  const candidates = [];
  const envBase = normalizeBase(process.env.REACT_APP_API_BASE_URL);

  if (envBase) {
    candidates.push(envBase);
  }

  candidates.push("http://127.0.0.1:8000");
  candidates.push("http://localhost:8000");

  return Array.from(new Set(candidates.filter(Boolean)));
}

export function getApiBaseUrl() {
  return getApiBaseCandidates()[0];
}

async function readJson(response) {
  const raw = await response.text();
  let data = {};

  if (raw) {
    try {
      data = JSON.parse(raw);
    } catch (error) {
      throw new Error("Reponse API invalide (non JSON). Verifie l URL de l API.");
    }
  }

  if (!response.ok) {
    throw new Error(data.message || "Erreur API (" + response.status + ")");
  }
  return data;
}

export async function apiRequest(path, method, token, payload) {
  const headers = { Accept: "application/json" };
  if (token) {
    headers.Authorization = "Bearer " + token;
  }
  if (payload !== undefined) {
    headers["Content-Type"] = "application/json";
  }

  const bases = getApiBaseCandidates();
  let lastNetworkError = null;

  for (let i = 0; i < bases.length; i += 1) {
    const base = bases[i];
    try {
      const response = await fetch(base + path, {
        method: method,
        headers: headers,
        body: payload !== undefined ? JSON.stringify(payload) : undefined
      });
      return readJson(response);
    } catch (error) {
      const isNetworkError =
        error && String(error.message || "").toLowerCase().indexOf("failed to fetch") > -1;
      if (!isNetworkError) {
        throw error;
      }
      lastNetworkError = error;
    }
  }

  throw lastNetworkError || new Error("API inaccessible.");
}
