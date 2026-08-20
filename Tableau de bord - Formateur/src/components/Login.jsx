import React, { Component } from "react";
import axios from "axios";

class Login extends Component{
  state = {
    email: "",
    password: "",
    isLoading: false,
    errorMessage: ""
  };

  handleChange = event => {
    const { name, value } = event.target;
    this.setState({ [name]: value });
  };

  onFormSubmit = async event => {
    event.preventDefault();
    this.setState({ isLoading: true, errorMessage: "" });

    try {
      const apiBaseUrl = (process.env.REACT_APP_API_BASE_URL || "http://127.0.0.1:8000").replace(/\/+$/, "");
      const response = await axios.post(apiBaseUrl + "/api/login", {
        email: this.state.email,
        password: this.state.password
      });

      localStorage.setItem("skillhub_token", response.data.token);
      localStorage.setItem("token", response.data.token);

      if (this.props.history && typeof this.props.history.push === "function") {
        this.props.history.push("/");
      }
    } catch (error) {
      this.setState({ 
        errorMessage: "Invalid credentials or server error", 
        isLoading: false 
      });
    }
  };

  render() {
    return (
      <div className="ui segment">
        <h3>Login</h3>
        <form className="ui form" onSubmit={this.onFormSubmit}>
          <div className="field">
            <label>Email</label>
            <input
              type="email"
              name="email"
              placeholder="Enter your email"
              value={this.state.email}
              onChange={this.handleChange}
              required
            />
          </div>
          <div className="field">
            <label>Password</label>
            <input
              type="password"
              name="password"
              placeholder="Enter your password"
              value={this.state.password}
              onChange={this.handleChange}
              required
            />
          </div>
          
          {this.state.errorMessage && (
            <div className="ui negative message">
              <p>{this.state.errorMessage}</p>
            </div>
          )}

          <button 
            className={`ui button primary ${this.state.isLoading ? "loading" : ""}`} 
            type="submit"
          >
            Sign In
          </button>
        </form>
      </div>
    );
  }
}

export default Login;
