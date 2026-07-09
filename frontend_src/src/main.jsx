import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import './styles.css';
import DiurnalExplorer from './DiurnalExplorer.jsx';

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <DiurnalExplorer />
  </StrictMode>,
);
