# Page snapshot

```yaml
- generic [ref=e1]:
  - heading "Log In" [level=1] [ref=e2]
  - generic [ref=e3]:
    - link "Powered by WordPress" [ref=e4] [cursor=pointer]:
      - /url: https://wordpress.org/
    - paragraph [ref=e6]:
      - strong [ref=e7]: "Error:"
      - text: The password you entered for the username
      - strong [ref=e8]: admin
      - text: is incorrect.
      - link "Lost your password?" [ref=e9] [cursor=pointer]:
        - /url: http://localhost:8881/wp-login.php?action=lostpassword
    - generic [ref=e10]:
      - paragraph [ref=e11]:
        - generic [ref=e12]: Username or Email Address
        - textbox "Username or Email Address" [ref=e13]: admin
      - generic [ref=e14]:
        - generic [ref=e15]: Password
        - generic [ref=e16]:
          - textbox "Password" [active] [ref=e17]
          - button "Show password" [ref=e18] [cursor=pointer]
      - paragraph [ref=e20]:
        - checkbox "Remember Me" [ref=e21] [cursor=pointer]
        - generic [ref=e22]: Remember Me
      - paragraph:
        - button "Log In" [ref=e23] [cursor=pointer]
    - paragraph [ref=e24]:
      - link "Lost your password?" [ref=e25] [cursor=pointer]:
        - /url: http://localhost:8881/wp-login.php?action=lostpassword
    - paragraph [ref=e26]:
      - link "← Go to VisitBrooklyn.NYC" [ref=e27] [cursor=pointer]:
        - /url: http://localhost:8881/
```