import React from "react";
import { createRoot } from "react-dom/client";
import App from "./components/App";

// React 18 : ReactDOM.render est remplace par createRoot, seule API qui active
// le rendu concurrent. L'ancien appel emettait un avertissement de depreciation.
createRoot(document.querySelector("#root")).render(<App />);
