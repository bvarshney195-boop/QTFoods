import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App';
import { FeedbackBehavior } from './components/FeedbackBehavior';
import './styles/app.css';

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode><FeedbackBehavior /><App /></React.StrictMode>
);
